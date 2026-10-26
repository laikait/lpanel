<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Support\Coercion;

/**
 * Escaping, by context.
 *
 * PHP templates do not escape anything on their own, and that is the single
 * largest hazard in using them. This framework does not fix it by inventing a
 * syntax -- that road ends at a compiler nobody asked for, and the specification
 * says not to copy Blade. It fixes it by making the correct call short enough
 * that there is no excuse: every PHP template gets `$e` in scope.
 *
 *     <p><?= $e($customer->name) ?></p>
 *     <a href="<?= $e->url($link) ?>" title="<?= $e->attr($tip) ?>">
 *     <script>const id = <?= $e->js($id) ?>;</script>
 *
 * The contexts are separate because escaping is not one operation. HTML-escaping
 * a value that lands inside a JavaScript string literal does nothing useful and
 * gives everybody involved the impression the problem is handled.
 *
 * Twig does this automatically, which is a real argument for using it. Both are
 * offered; neither is imposed.
 */
final class Escaper
{
    private const FLAGS = \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5;

    public function __construct(private readonly string $charset = 'UTF-8') {}

    /** Text between tags, and the default when $e is called directly. */
    public function __invoke(mixed $value): string
    {
        return $this->html($value);
    }

    public function html(mixed $value): string
    {
        return \htmlspecialchars($this->stringify($value), self::FLAGS, $this->charset);
    }

    /**
     * An attribute value.
     *
     * Identical to html() when the attribute is quoted, which it always should
     * be. It exists as its own method so that a template says which context it
     * meant, and so that this can tighten later without every call site moving.
     */
    public function attr(mixed $value): string
    {
        return $this->html($value);
    }

    /**
     * A value inside a <script> block, as a complete JSON literal.
     *
     * Not a quoted string: the caller writes `const id = <?= $e->js($id) ?>;`
     * and gets a number, a string, an array or null, correctly quoted. HEX_TAG
     * and friends are what stop a "</script>" inside the data from ending the
     * block early, which is the way this goes wrong in practice.
     */
    public function js(mixed $value): string
    {
        $json = \json_encode(
            $value,
            \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES,
        );

        return $json === false ? 'null' : $json;
    }

    /** A single query-string value or path segment. */
    public function url(mixed $value): string
    {
        return \rawurlencode($this->stringify($value));
    }

    /**
     * Deliberately unescaped output.
     *
     * Named so that it is visible in a diff. A template that is full of raw()
     * is telling you something.
     */
    public function raw(mixed $value): string
    {
        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if ($value === null || \is_bool($value)) {
            // "1"/"" for a bool is a silent surprise in a template; an empty
            // string for both false and null is at least predictable.
            return $value === true ? '1' : '';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        // Not a plain cast: fdiv() and sqrt(-1) produce floats that PHP 8.5
        // warns about converting, and a warning raised while rendering a page
        // is the worst possible moment for one.
        if (\is_float($value)) {
            return Coercion::fromFloat($value);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        // An array or a plain object reaching output is a mistake in the
        // template, and "Array" printed in a page is how it usually gets found
        // three weeks later.
        return throw new \InvalidArgumentException(\sprintf(
            'A %s cannot be printed. Format it in the handler, or loop over it in the template.',
            \get_debug_type($value),
        ));
    }
}
