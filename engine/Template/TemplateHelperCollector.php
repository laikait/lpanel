<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * What a module's templates() closure receives.
 *
 * The same shape as RouteCollector and CommandCollector: it writes into the
 * application-wide registry and stamps every declaration with the module that
 * made it, so a clash names both modules and template:list can say where a
 * helper came from.
 */
final class TemplateHelperCollector
{
    public function __construct(
        private readonly TemplateHelpers $helpers,
        private readonly string $module,
    ) {}

    /**
     * `{{ value|name(args) }}` in Twig, `$view->filter('name', $value, ...args)` in PHP.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     * @param bool $safe the callback returns markup it has escaped itself
     */
    public function filter(string $name, mixed $callback, bool $safe = false): self
    {
        $this->helpers->addFilter($name, $callback, $safe, $this->module);

        return $this;
    }

    /**
     * `{{ name(args) }}` in Twig, `$view->call('name', ...args)` in PHP.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     * @param bool $safe the callback returns markup it has escaped itself
     */
    public function function(string $name, mixed $callback, bool $safe = false): self
    {
        $this->helpers->addFunction($name, $callback, $safe, $this->module);

        return $this;
    }
}
