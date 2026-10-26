<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * The filters and functions modules have offered to templates.
 *
 * A module declares them in module.php, the same way it declares routes:
 *
 *     $module->templates(static function (TemplateHelperCollector $templates): void {
 *         $templates->filter('money', [Money::class, 'format']);
 *         $templates->function('route', [Links::class, 'route']);
 *     });
 *
 * and every template, Twig or PHP, can use them:
 *
 *     {{ invoice.total|money }}               <?= $e($view->filter('money', $invoice->total)) ?>
 *     {{ route('invoice', {id: invoice.id}) }} <?= $e($view->call('route', 'invoice', ['id' => $id])) ?>
 *
 * **Why this is not a way for a template to reach the container.** A template
 * can call exactly the names some module chose to offer, with the arguments
 * the template has. It cannot name a class, and it cannot ask for a service.
 * What a helper does is written in the module that declared it, next to its
 * routes, where a reviewer reads it.
 *
 * **Callbacks are resolved when first called, not when declared.**
 * `[Money::class, 'format']` names an instance method; the object is built by
 * the resolver Bootstrap hands this class — the container — the first time a
 * template calls it, exactly as `[Handler::class, 'method']` is for a route.
 * Registration cannot build anything, because the container is write-only
 * while modules register. A closure or a static method is called as it is.
 *
 * **Names are unique across the application.** Two modules offering "money"
 * would make which one a template got depend on module order, so the second is
 * refused at boot with both modules named. `local` belongs to the engine's
 * localization and cannot be taken.
 */
final class TemplateHelpers
{
    /** The shape a helper name must have: what Twig and PHP can both spell. */
    public const NAME_PATTERN = '/^[a-z_][a-z0-9_]*$/';

    /** Names the engine itself provides to every template. */
    public const RESERVED = ['local'];

    /** @var array<string, TemplateHelper> */
    private array $filters = [];

    /** @var array<string, TemplateHelper> */
    private array $functions = [];

    /** @var array<string, callable> resolved callbacks, keyed "kind:name" */
    private array $callables = [];

    /** @var array<class-string, object> one object per class, however many helpers name it */
    private array $objects = [];

    public function __construct(
        /**
         * Builds the object an [Class::class, 'method'] callback names. Null
         * means only closures, static methods and objects can be called.
         *
         * @var (\Closure(class-string): object)|null
         */
        private readonly ?\Closure $resolver = null,
    ) {}

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function addFilter(string $name, mixed $callback, bool $safe = false, string $module = 'engine'): void
    {
        $this->filters[$name] = $this->helper(TemplateHelper::FILTER, $name, $callback, $safe, $module, $this->filters);
    }

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function addFunction(string $name, mixed $callback, bool $safe = false, string $module = 'engine'): void
    {
        $this->functions[$name] = $this->helper(TemplateHelper::FUNCTION, $name, $callback, $safe, $module, $this->functions);
    }

    /** @return array<string, TemplateHelper> in declaration order */
    public function filters(): array
    {
        return $this->filters;
    }

    /** @return array<string, TemplateHelper> in declaration order */
    public function functions(): array
    {
        return $this->functions;
    }

    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function hasFunction(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /** Apply a filter: the value first, then the filter's own arguments. */
    public function applyFilter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        $helper = $this->filters[$name] ?? throw TemplateException::unknownHelper(TemplateHelper::FILTER, $name);

        return $this->callable($helper)($value, ...$arguments);
    }

    public function callFunction(string $name, mixed ...$arguments): mixed
    {
        $helper = $this->functions[$name] ?? throw TemplateException::unknownHelper(TemplateHelper::FUNCTION, $name);

        return $this->callable($helper)(...$arguments);
    }

    /**
     * The callable behind a helper, built on first use and kept.
     *
     * Kept per process rather than rebuilt per call: a page that formats forty
     * amounts should build one Money, not forty -- and a Money behind both
     * |money and |money_short is built once too.
     */
    public function callable(TemplateHelper $helper): callable
    {
        $key = $helper->kind . ':' . $helper->name;

        if (isset($this->callables[$key])) {
            return $this->callables[$key];
        }

        $callback = $helper->callback;

        if (\is_array($callback) && \is_string($callback[0]) && !\is_callable($callback)) {
            if ($this->resolver === null) {
                throw TemplateException::helperNotCallable($helper, 'nothing can build its object');
            }

            $callback = [$this->objects[$callback[0]] ??= ($this->resolver)($callback[0]), $callback[1]];
        }

        if (!\is_callable($callback)) {
            throw TemplateException::helperNotCallable($helper, 'it is not callable');
        }

        return $this->callables[$key] = $callback;
    }

    /** Forget resolved objects; a long-running worker between jobs. */
    public function flush(): void
    {
        $this->callables = [];
        $this->objects = [];
    }

    /**
     * Check a declaration where it was written.
     *
     * A callback naming a class or method that does not exist is refused here,
     * at boot, rather than on the first page that happens to use it.
     *
     * @param TemplateHelper::FILTER|TemplateHelper::FUNCTION $kind
     * @param array<string, TemplateHelper> $existing
     */
    private function helper(string $kind, string $name, mixed $callback, bool $safe, string $module, array $existing): TemplateHelper
    {
        if (\preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw TemplateException::unacceptableHelperName($kind, $name, $module);
        }

        if (\in_array($name, self::RESERVED, true)) {
            throw TemplateException::reservedHelperName($kind, $name, $module);
        }

        if (isset($existing[$name])) {
            throw TemplateException::duplicateHelper($kind, $name, $existing[$name]->module, $module);
        }

        $helper = new TemplateHelper($kind, $name, $callback, $safe, $module);

        $valid = $callback instanceof \Closure
            || (\is_string($callback) && \is_callable($callback))
            || (\is_array($callback) && \count($callback) === 2 && \array_is_list($callback)
                && (\is_object($callback[0]) || (\is_string($callback[0]) && \class_exists($callback[0])))
                && \is_string($callback[1]) && \method_exists($callback[0], $callback[1]));

        if (!$valid) {
            throw TemplateException::helperNotCallable($helper, 'the class or method does not exist');
        }

        return $helper;
    }
}
