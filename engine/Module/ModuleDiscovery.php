<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Config\Config;
use App\Engine\Support\Path;

/**
 * The Discover stage: a filesystem walk, and nothing else.
 *
 * No module code runs here. The configured roots are scanned for module.php
 * files -- every directory directly under a root that has one is a module,
 * named after its directory, whatever that is -- and each definition records what a later stage would otherwise have to
 * ask the filesystem again -- whether the module has an assets/ directory and
 * whether it has a Templates/ one. That is what lets a boot from the discovery
 * cache touch no module directory at all: the cache holds these answers, and
 * nothing after this stage asks the questions.
 *
 * It is its own class rather than a few methods on the manager for two reasons.
 * One is that building the cache must scan afresh -- a process that booted from
 * an old cache and wrote its registry back would make the old cache permanent --
 * and that needs discovery without a manager that may already have run. The
 * other is that "only discovery probes module directories" is a rule worth being
 * able to check, and a rule about one file is checkable.
 */
final class ModuleDiscovery
{
    /**
     * @param list<string> $roots directories of modules, or a module directory itself; relative
     *                            to the base path or absolute
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $roots,
    ) {}

    public static function fromConfig(Config $config, string $basePath): self
    {
        /** @var mixed $paths */
        $paths = $config->get('modules.paths', []);
        $roots = [];

        if (\is_string($paths)) {
            $paths = [$paths];
        }

        if (\is_array($paths)) {
            foreach ($paths as $path) {
                if (\is_string($path) && $path !== '') {
                    $roots[] = $path;
                }
            }
        }

        return new self($basePath, $roots);
    }

    /**
     * The roots this discovery walks, as absolute paths, in configured order.
     *
     * The discovery cache stores these and is ignored when they differ, which
     * covers the two ways a cache goes wrong without anybody editing a module:
     * modules.paths changed, or the application now lives in another directory.
     * The second is not exotic -- a test suite pointing at fixture modules, in a
     * checkout where somebody once ran cache:warm, is exactly that.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return \array_map(
            fn(string $relative): string => Path::normalize(
                Path::isAbsolute($relative) ? $relative : Path::join($this->basePath, $relative),
            ),
            $this->roots,
        );
    }

    /**
     * Every module under the configured roots, in no particular order.
     *
     * A root is normally a directory of modules -- modules/ -- and every
     * directory directly inside it that has a module.php is one. A root that
     * has a module.php of its own is a single module instead, which is how a
     * module kept outside modules/ is added without moving it.
     *
     * Order is the registry's job, and deliberately not filesystem order; see
     * ModuleRegistry.
     *
     * @return list<ModuleDefinition>
     *
     * @throws ModuleException when a module's directory name cannot be a module name
     */
    public function scan(): array
    {
        $found = [];

        foreach ($this->roots() as $root) {
            if (\is_file(Path::join($root, 'module.php'))) {
                $found[] = $this->module($root, \basename($root));

                continue;
            }

            foreach ($this->entries($root) as $entry) {
                $path = Path::join($root, $entry);

                if (\is_dir($path) && \is_file(Path::join($path, 'module.php'))) {
                    $found[] = $this->module($path, $entry);
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function entries(string $root): array
    {
        if (!\is_dir($root)) {
            return [];
        }

        $entries = \scandir($root);

        if ($entries === false) {
            return [];
        }

        return \array_values(\array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    private function module(string $path, string $directory): ModuleDefinition
    {
        if (\preg_match(ModuleDefinition::NAME_PATTERN, $directory) !== 1) {
            throw ModuleException::invalidModuleName($directory, $path);
        }

        return ModuleDefinition::create(
            $path,
            $directory,
            hasAssets: \is_dir(Path::join($path, ModuleDefinition::ASSETS)),
            hasTemplates: \is_dir(Path::join($path, ModuleDefinition::TEMPLATES)),
            hasLang: \is_dir(Path::join($path, ModuleDefinition::LANG)),
        );
    }
}
