<?php

declare(strict_types=1);

namespace App\Engine\Support;

use App\Engine\Asset\AssetManager;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Template\TemplateManager;

/**
 * The bridge between the global helpers and the subsystems behind them.
 *
 * This class is the one place the framework holds framework state statically,
 * and it exists to resolve a genuine tension: facades are banned, and the global
 * helpers add_hook()/apply_filter() are mandated. Those are only contradictory
 * if "reachable globally" and "facade" mean the same thing.
 *
 * A facade is a class with __callStatic that resolves ARBITRARY services from a
 * global container, producing call sites no static analyser can type, and
 * growing one class per service. This is a closed holder with one concrete
 * typed accessor per subsystem and no dynamic dispatch of any kind.
 *
 * **What decides the membership.** A subsystem belongs here when the
 * specification says application authors reach it globally: the hook and filter
 * helpers, asset() and template(). Those are the places with no constructor to
 * inject into -- a module.php file, a template, a one-off extension.
 * Everything else in the framework is injected, including from inside this
 * directory.
 *
 * That rule is the whole safety argument, and it has to be a rule about *why* a
 * member is here rather than a count, because a count is only ever one commit
 * from being changed to the next number. Adding a member means finding the
 * sentence in the specification that mandates a global for it. If there isn't
 * one, it is a service and it gets injected.
 *
 * Three rules keep it honest, all enforced by tests/Architecture:
 *
 *   1. The members are exactly those listed above, asserted by name. Growing
 *      the list is a design review, not a commit. The list is now closed: the
 *      specification mandates no further global.
 *   2. No file under engine/ may call a global helper, helpers.php excepted.
 *      Engine code takes its collaborators by constructor injection.
 *   3. There is no dynamic dispatch here and never will be. The moment a
 *      lookup takes a string, this is a service locator.
 */
final class Extensions
{
    private static ?HookEngine $hooks = null;

    private static ?FilterEngine $filters = null;

    private static ?AssetManager $assets = null;

    private static ?TemplateManager $templates = null;

    /**
     * The last two are nullable because the hook and filter engines exist from
     * the first line of bootstrap while the asset and template managers need
     * configuration and a registry. A test that only cares about hooks should
     * not have to build either, and asset()/template() say so plainly if they
     * are reached anyway.
     */
    public static function init(
        HookEngine $hooks,
        FilterEngine $filters,
        ?AssetManager $assets = null,
        ?TemplateManager $templates = null,
    ): void {
        self::$hooks = $hooks;
        self::$filters = $filters;
        self::$assets = $assets;
        self::$templates = $templates;
    }

    public static function hooks(): HookEngine
    {
        return self::$hooks ?? throw new \LogicException(
            'add_hook()/do_hook() were used before the application was bootstrapped. '
            . 'Hooks can only be registered once the engines exist.',
        );
    }

    public static function filters(): FilterEngine
    {
        return self::$filters ?? throw new \LogicException(
            'add_filter()/apply_filter() were used before the application was bootstrapped. '
            . 'Filters can only be registered once the engines exist.',
        );
    }

    public static function assets(): AssetManager
    {
        return self::$assets ?? throw new \LogicException(
            'asset() was used before the asset manager existed. '
            . 'Assets are published during module registration, so this is either '
            . 'code running before bootstrap or a container built without one.',
        );
    }

    public static function templates(): TemplateManager
    {
        return self::$templates ?? throw new \LogicException(
            'template() was used before the template manager existed. '
            . 'Templates are registered during module registration, so this is either '
            . 'code running before bootstrap or a container built without one.',
        );
    }

    public static function isInitialised(): bool
    {
        return self::$hooks !== null && self::$filters !== null;
    }

    /**
     * Forget the engines.
     *
     * Tests use this to prove the helpers are wired rather than ambient, and to
     * keep one test's listeners out of the next test's run.
     */
    public static function reset(): void
    {
        self::$hooks = null;
        self::$filters = null;
        self::$assets = null;
        self::$templates = null;
    }
}
