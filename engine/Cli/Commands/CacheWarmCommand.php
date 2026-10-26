<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Core\Application;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleRegistry;

/**
 * Build the production boot path: the configuration cache and the module
 * discovery cache, in one deployment step.
 *
 * **Why a command and not a setting.** Both caches are stale-until-cleared, and
 * a stale-until-cleared cache has to be something somebody asked for at a moment
 * when nothing is about to change. A setting that makes the first request write
 * one is how a cache gets built on a laptop halfway through adding a module.
 * The file existing is the switch; this is how it comes to exist; cache:clear is
 * how it stops.
 *
 * **Why it refuses in debug.** A debug process ignores the module cache -- the
 * specification's development mode is "uncached module discovery" -- so warming
 * it there builds something nothing will read, and a configuration cache built
 * from a debug configuration is a debug configuration waiting to be deployed.
 *
 * **What it does not build, and why**, since the specification lists both:
 *
 *   Routes. A route is declared inside module.php, next to the hooks and
 *   services that must be registered on every boot regardless, so the
 *   declaration always runs; what a cache could skip is compiling the table,
 *   which measures at about 1.5 ms for 500 routes on an unoptimised machine and
 *   happens once per process, lazily, and never for a request that does not
 *   route. Handlers may also be closures, which no cache file can hold.
 *
 *   Dependency resolution. Tens of microseconds, and it depends on what every
 *   module.php declares -- a cached graph is wrong the first time somebody
 *   edits one.
 *
 *   MCP capabilities, for the reason routes are not: they are declared in
 *   module.php, and registering a hundred measures at about 0.15 ms.
 *
 * All three are benchmarked (composer bench), so the day one stops being true
 * is a number rather than a feeling.
 */
final class CacheWarmCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
        private readonly ConfigCacheCommand $configCache,
    ) {}

    public function __invoke(Output $output): int
    {
        if ($this->config->bool('app.debug', false)) {
            $output->error(
                'APP_DEBUG is on. A debug process ignores the module cache, and a configuration cache built '
                . 'from a debug configuration would carry debug into production. Warm the caches where they '
                . 'will be used, with debug off.',
            );

            return 1;
        }

        $base = $this->application->basePath();
        $built = $this->configCache->build();

        if ($built === null) {
            $output->error('The configuration cache could not be written to ' . ConfigCache::file($base));

            return 1;
        }

        // The configuration just read from disk, not the one this process
        // booted with: that one may itself have come from an older cache, and
        // module roots are part of what a deployment changes.
        $fresh = new Config($built['items']);

        if ($fresh->bool('app.debug', false)) {
            @\unlink(ConfigCache::file($base));
            $output->error('The configuration on disk turns debug on. Nothing was cached.');

            return 1;
        }

        // Scanned afresh for the same reason. Writing back the registry this
        // process booted from would make an old cache permanent.
        $registry = new ModuleRegistry();
        $discovery = ModuleDiscovery::fromConfig($fresh, $base);

        foreach ($discovery->scan() as $definition) {
            $registry->add($definition);
        }

        $modules = ModuleRegistry::cacheFile($base);

        if (!$registry->writeCache($modules, $discovery->roots())) {
            $output->error('The module cache could not be written to ' . $modules);

            return 1;
        }

        $output->success('Boot path cached.');
        $output->pairs([
            'Configuration' => \sprintf(
                '%s (%d environment variable%s fingerprinted)',
                ConfigCache::file($base),
                \count($built['environment']),
                \count($built['environment']) === 1 ? '' : 's',
            ),
            'Modules' => \sprintf('%s (%d installed)', $modules, \count($registry->installed())),
            'Routes' => 'not cached -- declared by module.php, which runs on every boot anyway',
            'Dependencies' => 'not cached -- resolved in microseconds from what module.php declares',
            'MCP' => 'not cached -- capabilities are declared by module.php, like routes (see mcp:list)',
        ]);

        $output->line();
        $output->line('Neither cache notices a file being edited or a module being added. Run cache:clear when you deploy.');

        return 0;
    }
}
