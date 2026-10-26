<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Support\Path;

/**
 * Holds what discovery found, in a deterministic order.
 *
 * Order is Shared first, then every other module by directory name,
 * case-sensitively. It is never filesystem order:
 * readdir() ordering varies between filesystems and platforms, and a framework
 * whose behaviour depends on it is a framework that behaves differently in
 * production than on a developer's laptop.
 *
 * That Shared always comes first is what makes it genuinely shared.
 *
 * Two refinements since modules could depend on each other. Once dependencies
 * are resolved the order is the resolver's, which differs from the discovery
 * order only where a dependency forced it. And a module can be **installed but
 * disabled**: it is still known here, so that "disabled" and "missing" can be
 * told apart in an error, but it is left out of everything that loads, orders,
 * publishes or registers.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $definitions = [];

    /** @var array<string, ModuleContext> */
    private array $contexts = [];

    /** @var array<string, true> */
    private array $disabled = [];

    /** @var list<string>|null the resolved order, once there is one */
    private ?array $order = null;

    private bool $sorted = true;

    public function add(ModuleDefinition $definition): void
    {
        $existing = $this->definitions[$definition->id] ?? null;

        if ($existing !== null && $existing->path !== $definition->path) {
            throw ModuleException::duplicateId($definition->id, $existing->path, $definition->path);
        }

        $this->definitions[$definition->id] = $definition;
        $this->sorted = false;
        $this->order = null;
    }

    /** Installed, whether or not it is enabled. */
    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * Installed and not disabled.
     *
     * The question an optional integration asks at boot, through an injected
     * ModuleRegistry, when listening for the other module's hooks is not
     * enough.
     */
    public function isEnabled(string $id): bool
    {
        return $this->has($id) && !isset($this->disabled[$id]);
    }

    public function isDisabled(string $id): bool
    {
        return isset($this->disabled[$id]);
    }

    public function definition(string $id): ?ModuleDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /**
     * Enabled modules, in the order they register.
     *
     * @return list<ModuleDefinition>
     */
    public function definitions(): array
    {
        return \array_map(fn(string $id): ModuleDefinition => $this->definitions[$id], $this->ids());
    }

    /**
     * Enabled module ids, in the order they register.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $this->sort();

        return \array_values(\array_filter(
            \array_keys($this->definitions),
            fn(string $id): bool => !isset($this->disabled[$id]),
        ));
    }

    /**
     * Everything discovery found, disabled modules included, in discovery order.
     *
     * @return list<ModuleDefinition>
     */
    public function installed(): array
    {
        $this->sort();

        return \array_values($this->definitions);
    }

    /** @return list<string> */
    public function disabledIds(): array
    {
        $this->sort();

        return \array_values(\array_filter(
            \array_keys($this->definitions),
            fn(string $id): bool => isset($this->disabled[$id]),
        ));
    }

    /**
     * Switch an installed module off.
     *
     * Only an installed one: an unknown id is refused by the manager with the
     * list of what is installed, because a typo in modules.disabled would
     * otherwise leave a module running while the configuration says it is off.
     */
    public function disable(string $id): void
    {
        if (!$this->has($id)) {
            throw ModuleException::unknownDisabledModule($id, \array_keys($this->definitions));
        }

        $this->disabled[$id] = true;
        $this->order = null;
    }

    /**
     * Fix the registration order to what the resolver decided.
     *
     * Checked, because a list that dropped or invented a module would make the
     * application silently not register something.
     *
     * @param list<string> $ids
     */
    public function setOrder(array $ids): void
    {
        $this->order = null;
        $expected = $this->ids();

        $given = $ids;
        \sort($given);
        \sort($expected);

        if ($given !== $expected) {
            throw new \LogicException('A module order must name every enabled module exactly once.');
        }

        $this->order = \array_values($ids);
    }

    public function setContext(ModuleContext $context): void
    {
        $this->contexts[$context->id()] = $context;
    }

    public function context(string $id): ?ModuleContext
    {
        return $this->contexts[$id] ?? null;
    }

    /**
     * Contexts in module order.
     *
     * @return list<ModuleContext>
     */
    public function contexts(): array
    {
        $ordered = [];

        foreach ($this->ids() as $id) {
            if (isset($this->contexts[$id])) {
                $ordered[] = $this->contexts[$id];
            }
        }

        return $ordered;
    }

    /** Enabled modules. */
    public function count(): int
    {
        return \count($this->ids());
    }

    public function clear(): void
    {
        $this->definitions = [];
        $this->contexts = [];
        $this->disabled = [];
        $this->order = null;
        $this->sorted = true;
    }

    // ---- discovery cache --------------------------------------------------

    /**
     * The cacheable artefact: plain scalars only, and only what discovery found.
     *
     * **Installed modules, not enabled ones, and no dependency data.** Both are
     * deliberate. Disabling is configuration, applied after the cache is read,
     * so switching a module off never needs the cache cleared. And the resolved
     * order is not stored because it depends on what every module.php declares:
     * a cached graph would be wrong the first time somebody edited one, and
     * this cache already has no automatic invalidation.
     *
     * What it does carry beyond the location is whether each module has an
     * assets/ and a Templates/ directory -- filesystem facts, found by the same
     * walk, and the reason a boot from this cache probes no module directory.
     *
     * @return list<array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool, lang: bool}>
     */
    public function toArray(): array
    {
        return \array_map(
            static fn(ModuleDefinition $definition): array => $definition->toArray(),
            $this->installed(),
        );
    }

    /**
     * @param list<array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool, lang: bool}> $data
     */
    public function loadArray(array $data): void
    {
        $this->clear();

        foreach ($data as $entry) {
            $this->add(ModuleDefinition::fromArray($entry));
        }
    }

    /**
     * Write the discovery cache.
     *
     * var_export() rather than serialize() so the file is opcache-friendly and
     * readable when something goes wrong.
     *
     * @param list<string> $roots the absolute roots the modules were found under;
     *                                     see ModuleDiscovery::roots()
     */
    public function writeCache(string $file, array $roots = []): bool
    {
        $directory = \dirname($file);

        if (!\is_dir($directory) && !\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            return false;
        }

        $contents = "<?php\n\n// Generated module discovery cache, written by cache:warm. Run cache:clear, or delete\n"
            . "// this file, after adding or removing a module. A process with app.debug on ignores it.\n\nreturn "
            . \var_export(['roots' => $roots, 'modules' => $this->toArray()], true)
            . ";\n";

        // Write and rename so a concurrent reader never sees a half-written file.
        $temporary = $file . '.' . \getmypid() . '.tmp';

        if (\file_put_contents($temporary, $contents, \LOCK_EX) === false) {
            return false;
        }

        if (!\rename($temporary, $file)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Read the discovery cache.
     *
     * There is deliberately no automatic invalidation. Stat-ing every module
     * directory to decide whether the cache is stale would undo the saving the
     * cache exists to provide, and mtime is unreliable on Windows and on
     * network shares. Clearing the cache is deleting the file.
     *
     * Never throws, and never half-loads. Every entry is checked before any is
     * used, so a file from an older version or a hand edit means "scan instead"
     * rather than a boot that found some of its modules.
     *
     * A cache built under different roots is ignored too. That is the one kind
     * of staleness that costs nothing to detect -- the roots are already in
     * hand -- and it is the kind nobody would think to clear for.
     *
     * @param list<string> $roots the roots this process would scan
     */
    public function readCache(string $file, array $roots = []): bool
    {
        if (!\is_file($file)) {
            return false;
        }

        /** @var mixed $cached */
        $cached = require $file;

        if (!\is_array($cached) || ($cached['roots'] ?? null) !== $roots) {
            return false;
        }

        /** @var mixed $data */
        $data = $cached['modules'] ?? null;

        if (!\is_array($data) || !\array_is_list($data)) {
            return false;
        }

        foreach ($data as $entry) {
            if (!ModuleDefinition::isCachedShape($entry)) {
                return false;
            }
        }

        /** @var list<array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool, lang: bool}> $data */
        $this->loadArray($data);

        return true;
    }

    public static function cacheFile(string $basePath): string
    {
        return Path::join($basePath, 'system/Cache/modules.php');
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        \uasort(
            $this->definitions,
            static fn(ModuleDefinition $a, ModuleDefinition $b): int => $a->sortKey() <=> $b->sortKey(),
        );

        $this->sorted = true;
    }
}
