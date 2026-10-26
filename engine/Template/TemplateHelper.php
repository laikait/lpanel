<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * One filter or function a module offers to every template.
 *
 * A filter transforms the value in front of it — `{{ total|money }}`,
 * `$view->filter('money', $total)`. A function produces a value from its
 * arguments — `{{ route('invoice', {id: 7}) }}`, `$view->call('route', ...)`.
 * The difference is only in how a template spells the call; both end up as
 * the same callback.
 *
 * $safe says the callback returns markup it has already escaped. Twig then
 * prints it as it is; a PHP template decides for itself, as it always does,
 * with `$e->raw()`. A helper that returns text must leave it false, and that
 * is the default because the mistake in that direction is a double-escaped
 * `&amp;lt;` somebody notices rather than a hole nobody does.
 */
final class TemplateHelper
{
    public const FILTER = 'filter';

    public const FUNCTION = 'function';

    /**
     * @param self::FILTER|self::FUNCTION $kind
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly mixed $callback,
        public readonly bool $safe,
        public readonly string $module,
    ) {}

    /** "Money::format" -- class and method -- for template:list and messages. */
    public function describe(): string
    {
        $callback = $this->callback;

        if (\is_string($callback)) {
            return $callback;
        }

        if (\is_array($callback)) {
            $target = $callback[0];

            return (\is_object($target) ? $target::class : $target) . '::' . $callback[1];
        }

        return 'Closure';
    }
}
