<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleKind;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Module\ModuleStage;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Template\TemplateRegistry;
use App\Tests\Support\TestCase;

/**
 * Discovery, its cache, and the asset path that stops after it.
 *
 * Each test builds its own application tree in a temporary directory rather
 * than using the shared fixtures, for two reasons: it needs modules with and
 * without assets/ and Templates/, and it writes cache files, which have no
 * business appearing in the project's own system/Cache.
 *
 * Every module.php here records that it ran before returning its closure, so
 * "no module code ran" is an assertion rather than an inference.
 */
final class DiscoveryCacheTest extends TestCase
{
    private const LOADED = 'discovery_cache_test_loaded';

    private string $root;

    private AssetRegistry $assets;

    private TemplateRegistry $templates;

    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->root = \str_replace('\\', '/', \sys_get_temp_dir()) . '/discovery-cache-' . \bin2hex(\random_bytes(6));
        $GLOBALS[self::LOADED] = [];

        $this->module('Shared', templates: true);
        $this->module('Lazy', assets: true, templates: true);
        $this->module('Plain');
        $this->module('Pay', assets: true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        unset($GLOBALS[self::LOADED]);

        parent::tearDown();
    }

    // ---- discovery ---------------------------------------------------------

    public function test_discovery_records_whether_a_module_has_assets_and_templates(): void
    {
        $found = [];

        foreach ($this->discovery()->scan() as $definition) {
            $found[$definition->id] = [$definition->hasAssets, $definition->hasTemplates];
        }

        \ksort($found);

        self::assertSame([
            'Lazy' => [true, true],
            'Pay' => [true, false],
            'Plain' => [false, false],
            'Shared' => [false, true],
        ], $found);
    }

    public function test_the_roots_are_absolute_and_normalised(): void
    {
        self::assertSame([$this->root . '/modules'], $this->discovery()->roots());
    }

    public function test_a_module_is_any_directory_with_a_module_php_named_after_it(): void
    {
        $this->module('Anything_Goes2');
        \mkdir($this->root . '/modules/Notes');

        $ids = \array_map(static fn(ModuleDefinition $definition): string => $definition->id, $this->discovery()->scan());
        \sort($ids);

        self::assertSame(['Anything_Goes2', 'Lazy', 'Pay', 'Plain', 'Shared'], $ids, 'Notes has no module.php, so it is not a module');
        self::assertSame(ModuleKind::Shared, ModuleKind::of('Shared'));
    }

    public function test_a_directory_whose_name_cannot_be_a_module_is_refused(): void
    {
        \mkdir($this->root . '/modules/billing-app');
        \file_put_contents($this->root . '/modules/billing-app/module.php', '<?php return static function (): void {};');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('"billing-app", which cannot be a module name');

        $this->discovery()->scan();
    }

    public function test_a_path_with_its_own_module_php_is_one_module(): void
    {
        \mkdir($this->root . '/elsewhere/Extra', 0o777, true);
        \file_put_contents($this->root . '/elsewhere/Extra/module.php', '<?php return static function (): void {};');

        $ids = \array_map(
            static fn(ModuleDefinition $definition): string => $definition->id,
            (new ModuleDiscovery($this->root, ['modules', 'elsewhere/Extra']))->scan(),
        );

        self::assertContains('Extra', $ids);
        self::assertCount(5, $ids);
    }

    public function test_two_roots_cannot_both_hold_a_module_of_one_name(): void
    {
        \mkdir($this->root . '/more/Lazy', 0o777, true);
        \file_put_contents($this->root . '/more/Lazy/module.php', '<?php return static function (): void {};');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Two modules claim the id "Lazy"');

        $this->manager(['modules' => ['paths' => ['modules', 'more']]])->discover();
    }

    public function test_discovery_runs_no_module_code(): void
    {
        $this->manager()->discover();

        self::assertSame([], $GLOBALS[self::LOADED]);
    }

    // ---- the cache ---------------------------------------------------------

    /**
     * A request never writes it. The version of this cache that a first request
     * builds is the version built on a laptop halfway through adding a module.
     */
    public function test_discovery_never_writes_the_cache(): void
    {
        $this->manager()->run();

        self::assertFileDoesNotExist(ModuleRegistry::cacheFile($this->root));
    }

    /**
     * The cache names one module; four are on disk. Only one being found is the
     * proof that the directories were not walked.
     */
    public function test_a_warmed_cache_is_used_instead_of_a_scan(): void
    {
        $this->warm(static fn(ModuleDefinition $definition): bool => $definition->id === 'Shared');

        $manager = $this->manager();
        $manager->discover();

        self::assertSame(['Shared'], $this->registry->ids());
        self::assertTrue($manager->discoveredFromCache());
    }

    /** The specification's development mode: "uncached module discovery". */
    public function test_a_debug_process_never_reads_the_cache(): void
    {
        $this->warm(static fn(ModuleDefinition $definition): bool => $definition->id === 'Shared');

        $manager = $this->manager(['app' => ['debug' => true]]);
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    /**
     * modules.paths changed, or the application moved, or -- the case that
     * would actually happen -- a test suite points at other modules in a
     * checkout where somebody once ran cache:warm.
     */
    public function test_a_cache_built_under_other_roots_is_ignored(): void
    {
        $this->warm(
            static fn(ModuleDefinition $definition): bool => $definition->id === 'Shared',
            ['/somewhere/else'],
        );

        $manager = $this->manager();
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    public function test_a_cache_written_by_an_older_version_is_ignored(): void
    {
        $this->writeCacheFile(\var_export([
            ['id' => 'Shared', 'kind' => 'Shared', 'path' => $this->root . '/modules/Shared', 'entryFile' => 'x', 'directory' => 'Shared'],
        ], true));

        $manager = $this->manager();
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    /** One bad entry among good ones means scan, never a boot that found half its modules. */
    public function test_a_cache_with_one_malformed_entry_is_ignored_entirely(): void
    {
        $good = ModuleDefinition::create($this->root . '/modules/Shared', 'Shared')->toArray();
        $bad = [...$good, 'id' => 'Broken', 'assets' => 'yes'];

        $this->writeCacheFile(\var_export(['roots' => $this->discovery()->roots(), 'modules' => [$good, $bad]], true));

        $registry = new ModuleRegistry();

        self::assertFalse($registry->readCache(ModuleRegistry::cacheFile($this->root), $this->discovery()->roots()));
        self::assertSame([], $registry->ids());
    }

    public function test_the_cache_round_trips_the_directory_facts(): void
    {
        $this->warm(static fn(): bool => true);

        $registry = new ModuleRegistry();
        self::assertTrue($registry->readCache(ModuleRegistry::cacheFile($this->root), $this->discovery()->roots()));

        $lazy = $registry->definition('Lazy');
        self::assertNotNull($lazy);
        self::assertTrue($lazy->hasAssets);
        self::assertTrue($lazy->hasTemplates);
        self::assertFalse($registry->definition('Plain')?->hasAssets);
    }

    /**
     * Registration asks discovery's answer, not the filesystem. The cache below
     * says Lazy has no assets/ -- it does, on disk -- and it is not
     * published. That is the observable form of "a cached boot probes no module
     * directory".
     */
    public function test_publishing_uses_what_discovery_recorded_rather_than_asking_again(): void
    {
        $this->warm(static fn(): bool => true, transform: static fn(ModuleDefinition $definition): ModuleDefinition
            => $definition->id === 'Lazy'
                ? new ModuleDefinition($definition->id, $definition->kind, $definition->path, $definition->entryFile, $definition->directory, false, false)
                : $definition);

        $this->manager()->run();

        self::assertFalse($this->assets->has(AssetKind::Module, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Module, 'Pay'));
        self::assertFalse($this->templates->hasNamespace('Lazy'));
        self::assertTrue($this->templates->hasNamespace('Shared'));
    }

    // ---- the asset path ----------------------------------------------------

    public function test_preparing_for_an_asset_request_runs_no_module_code(): void
    {
        $manager = $this->manager();
        $manager->prepareAssets();

        self::assertSame([], $GLOBALS[self::LOADED], 'a module.php ran to serve an asset');
        self::assertSame([], $this->registry->contexts());
        self::assertSame(ModuleStage::Discovered, $manager->stage());

        self::assertTrue($this->assets->has(AssetKind::Module, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Module, 'Pay'));
        self::assertFalse($this->assets->has(AssetKind::Module, 'Plain'));
    }

    public function test_a_disabled_module_publishes_nothing_on_the_asset_path_either(): void
    {
        $this->manager(['modules' => ['disabled' => ['Lazy']]])->prepareAssets();

        self::assertFalse($this->assets->has(AssetKind::Module, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Module, 'Pay'));
    }

    /** A long-lived process may serve an asset and then a page. Nothing repeats. */
    public function test_a_full_boot_after_the_asset_path_repeats_nothing(): void
    {
        $manager = $this->manager();
        $manager->prepareAssets();
        $manager->run();

        $loaded = $GLOBALS[self::LOADED];
        \sort($loaded);

        self::assertSame(['Lazy', 'Pay', 'Plain', 'Shared'], $loaded);
        self::assertSame(ModuleStage::Ready, $manager->stage());
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $config */
    private function manager(array $config = []): ModuleManager
    {
        $this->assets = new AssetRegistry();
        $this->templates = new TemplateRegistry();
        $this->registry = new ModuleRegistry();

        return new ModuleManager(
            new Container(),
            new Config(\array_replace_recursive(['app' => ['debug' => false], 'modules' => ['paths' => self::PATHS]], $config)),
            new Router(),
            new HookEngine(),
            new FilterEngine(),
            $this->assets,
            $this->templates,
            new CommandRegistry(),
            new ScheduleRegistry(),
            new AccessRegistry(),
            $this->registry,
            $this->root,
        );
    }

    private const PATHS = [
        'modules',
    ];

    private function discovery(): ModuleDiscovery
    {
        return new ModuleDiscovery($this->root, self::PATHS);
    }

    /**
     * Write the cache the way cache:warm does, keeping only some modules.
     *
     * @param \Closure(ModuleDefinition): bool                  $keep
     * @param list<string>|null                                 $roots     the roots to record, if not the real ones
     * @param (\Closure(ModuleDefinition): ModuleDefinition)|null $transform
     */
    private function warm(\Closure $keep, ?array $roots = null, ?\Closure $transform = null): void
    {
        $registry = new ModuleRegistry();

        foreach ($this->discovery()->scan() as $definition) {
            if ($keep($definition)) {
                $registry->add($transform === null ? $definition : $transform($definition));
            }
        }

        self::assertTrue($registry->writeCache(ModuleRegistry::cacheFile($this->root), $roots ?? $this->discovery()->roots()));
    }

    private function writeCacheFile(string $exported): void
    {
        $file = ModuleRegistry::cacheFile($this->root);
        @\mkdir(\dirname($file), 0o777, true);
        \file_put_contents($file, "<?php\n\nreturn " . $exported . ";\n");
    }

    private function module(string $name, bool $assets = false, bool $templates = false): void
    {
        $directory = $this->root . '/modules/' . $name;
        \mkdir($directory, 0o777, true);

        $id = $name;

        \file_put_contents($directory . '/module.php', \str_replace(
            ['{KEY}', '{ID}'],
            [self::LOADED, $id],
            <<<'PHP'
                <?php

                declare(strict_types=1);

                $GLOBALS['{KEY}'][] = '{ID}';

                return static function (App\Engine\Module\ModuleContext $module): void {
                    $module->version('1.0.0');
                };

                PHP,
        ));

        if ($assets) {
            \mkdir($directory . '/assets/js', 0o777, true);
            \file_put_contents($directory . '/assets/js/app.js', "// asset\n");
        }

        if ($templates) {
            \mkdir($directory . '/Templates');
        }
    }

    private function remove(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
            }
        }

        @\rmdir($path);
    }
}
