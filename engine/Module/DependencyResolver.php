<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * Turns what modules declared about each other into the order they register in,
 * or refuses to.
 *
 * Runs once, after every module.php has run and before anything registers --
 * the only moment at which every declaration is known and none has taken
 * effect. Four refusals, each a boot failure naming both modules, because a
 * dependency problem found at runtime is found by whichever request first
 * touches the missing piece:
 *
 * 1. **missing** -- required, and not installed
 * 2. **disabled** -- required, installed, and switched off in modules.disabled
 * 3. **version conflict** -- installed, but its version does not fit
 * 4. **circular** -- no order exists in which each module follows what it needs
 *
 * Plus one the specification did not list and this design needs: a dependency
 * **against kind** -- Shared depending on anything. See below.
 *
 * **The order only changes where a dependency forces it.** This is a stable
 * topological sort: at every step it takes, of the modules whose dependencies
 * are all placed, the one that came first in the existing order (Shared, then
 * directory name). So an application with no declarations registers exactly as
 * it always did, and a dependency moves precisely one module -- the one that has
 * to wait -- rather than reshuffling everything after it.
 *
 * **Shared first is never broken, and that is enforced rather than hoped for.**
 * Every module may rely on Shared without declaring it, because Shared always
 * registers first. Shared depending on a module would either move that module
 * ahead of it -- breaking the guarantee for modules that never mentioned it --
 * or be unsatisfiable, so it is refused where it is declared. The consequence is
 * that the sort only ever moves ordinary modules among themselves, and "Shared
 * registers first" stays true by construction.
 *
 * Nothing here is cached. The inputs are in memory, the graph is a few dozen
 * nodes, and a cached order is a stale order the day somebody edits a
 * module.php -- the module discovery cache already has no automatic
 * invalidation, and this is not a second thing to remember to clear.
 */
final class DependencyResolver
{
    /**
     * @param list<ModuleContext> $modules  enabled modules, in discovery order
     * @param list<string>        $disabled ids installed but switched off
     *
     * @return list<string> module ids, in the order they must register
     *
     * @throws ModuleException for any of the refusals in the class docblock
     */
    public function resolve(array $modules, array $disabled = []): array
    {
        /** @var array<string, ModuleContext> $byId */
        $byId = [];
        /** @var array<string, int> $position */
        $position = [];

        foreach ($modules as $index => $module) {
            $byId[$module->id()] = $module;
            $position[$module->id()] = $index;
        }

        $disabledSet = \array_fill_keys($disabled, true);

        /** @var array<string, list<string>> $dependents who waits for this id */
        $dependents = [];
        /** @var array<string, list<string>> $needs what this id waits for */
        $needs = [];
        /** @var array<string, int> $waiting */
        $waiting = \array_fill_keys(\array_keys($byId), 0);

        foreach ($modules as $module) {
            foreach ($module->declaredDependencies() as $dependency) {
                $target = $this->check($module, $dependency, $byId, $disabledSet);

                if ($target === null) {
                    continue;
                }

                $dependents[$target][] = $module->id();
                $needs[$module->id()][] = $target;
                ++$waiting[$module->id()];
            }
        }

        return $this->order($position, $waiting, $dependents, $needs);
    }

    /**
     * Validate one declaration; answer the id to wait for, or null for an
     * optional dependency that is not there.
     *
     * @param array<string, ModuleContext> $byId
     * @param array<string, true>          $disabled
     */
    private function check(ModuleContext $module, Dependency $dependency, array $byId, array $disabled): ?string
    {
        if ($dependency->id === $module->id()) {
            throw ModuleException::circularDependency([$module->id(), $module->id()]);
        }

        // Before presence: Shared depending on a module is a mistake in the
        // declaration whether or not that module happens to be installed today.
        if ($module->kind() === ModuleKind::Shared) {
            throw ModuleException::dependencyAgainstKind($module->id(), $module->kind(), $dependency);
        }

        if (isset($disabled[$dependency->id])) {
            if ($dependency->optional) {
                return null;
            }

            throw ModuleException::disabledDependency($module->id(), $dependency);
        }

        $target = $byId[$dependency->id] ?? null;

        if ($target === null) {
            if ($dependency->optional) {
                return null;
            }

            throw ModuleException::missingDependency(
                $module->id(),
                $dependency,
                $this->closest($dependency->id, [...\array_keys($byId), ...\array_keys($disabled)]),
            );
        }

        $declared = $target->declaresVersion();

        if (!$dependency->constraint->allows(Version::parse($target->moduleVersion(), $target->id()))) {
            throw ModuleException::versionConflict(
                $module->id(),
                $dependency,
                $target->moduleVersion(),
                $declared,
            );
        }

        return $dependency->id;
    }

    /**
     * Kahn's algorithm, taking the earliest ready module each time.
     *
     * Quadratic in the number of modules, which is a few dozen. A heap would
     * be the textbook choice and would make the one property that matters --
     * "earliest in the existing order wins" -- harder to see.
     *
     * @param array<string, int>          $position
     * @param array<string, int>          $waiting
     * @param array<string, list<string>> $dependents
     * @param array<string, list<string>> $needs
     *
     * @return list<string>
     */
    private function order(array $position, array $waiting, array $dependents, array $needs): array
    {
        $ordered = [];
        $remaining = $position;

        while ($remaining !== []) {
            $next = null;

            foreach ($remaining as $id => $index) {
                if ($waiting[$id] === 0 && ($next === null || $index < $remaining[$next])) {
                    $next = $id;
                }
            }

            if ($next === null) {
                throw ModuleException::circularDependency($this->cycle($remaining, $needs));
            }

            $ordered[] = $next;
            unset($remaining[$next]);

            foreach ($dependents[$next] ?? [] as $dependent) {
                --$waiting[$dependent];
            }
        }

        return $ordered;
    }

    /**
     * One cycle among the modules that could not be placed, for the message.
     *
     * Every one of them is still waiting on another of them -- that is why they
     * could not be placed -- so walking "waits for" from any of them must come
     * back round. Starting from the earliest keeps the message the same on
     * every run, which matters when somebody is comparing two CI logs.
     *
     * @param array<string, int>          $remaining
     * @param array<string, list<string>> $needs
     *
     * @return list<string>
     */
    private function cycle(array $remaining, array $needs): array
    {
        \asort($remaining);

        $current = (string) \array_key_first($remaining);
        $path = [];

        while (!\in_array($current, $path, true)) {
            $path[] = $current;

            foreach ($needs[$current] ?? [] as $next) {
                if (isset($remaining[$next])) {
                    $current = $next;

                    continue 2;
                }
            }

            // Unreachable while the invariant above holds; a guard rather than
            // an infinite loop if it ever does not.
            return [...$path, $current];
        }

        $start = (int) \array_search($current, $path, true);

        return [...\array_slice($path, $start), $current];
    }

    /**
     * The installed id nearest a missing one, when it is plausibly a typo.
     *
     * Three edits is enough for `Biling` or a wrong letter case, and few
     * enough that a genuinely different module is not offered as the answer.
     * It is a suggestion in an error message, never a substitution: guessing
     * which module somebody meant is how the wrong one gets loaded.
     *
     * @param list<string> $installed
     */
    private function closest(string $id, array $installed): ?string
    {
        $best = null;
        $distance = 4;

        foreach ($installed as $candidate) {
            $d = \levenshtein($id, $candidate);

            if ($d < $distance) {
                $best = $candidate;
                $distance = $d;
            }
        }

        return $best;
    }
}
