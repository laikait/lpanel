<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Support\Path;

/**
 * Where templates are looked for, and in what order.
 *
 * Two kinds of source are registered:
 *
 *   - **Unnamespaced**, which is what a bare name like "customer/profile"
 *     searches. In practice this is the application's templates/ directory.
 *   - **Namespaced**, one per module: "@Billing/invoice" searches the
 *     Billing module's Templates/ directory.
 *
 * The override rule is one sentence, and it lives in searchPath() rather than
 * in a document: **an unnamespaced source at override precedence is also
 * searched for namespaced names, under a directory named after the namespace.**
 * So a site replaces a module's invoice by creating
 *
 *     templates/Billing/invoice.php
 *
 * and the module never knows. No hook, no registration, no edit to the module.
 * The module's own copy is the fallback, which is what makes it safe for the
 * module to keep shipping one.
 */
final class TemplateRegistry
{
    /** @var array<string, TemplateSource> keyed by TemplateSource::key() */
    private array $sources = [];

    /** @var array<string, list<string>> memoised search paths, per namespace */
    private array $paths = [];

    public function register(TemplateSource $source): void
    {
        $key = $source->key();

        if (!isset($this->sources[$key])) {
            $clash = $this->clashingSource($source);

            if ($clash !== null) {
                throw TemplateException::duplicateNamespace($clash);
            }
        }

        $this->sources[$key] = $source;
        $this->paths = [];
    }

    public function add(?string $namespace, string $root, int $precedence = TemplateSource::MODULE): TemplateSource
    {
        $source = new TemplateSource($namespace, $root, $precedence);
        $this->register($source);

        return $source;
    }

    public function hasNamespace(string $namespace): bool
    {
        foreach ($this->sources as $source) {
            if ($source->namespace === $namespace) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every registered namespace, sorted */
    public function namespaces(): array
    {
        $namespaces = [];

        foreach ($this->sources as $source) {
            if ($source->namespace !== null) {
                $namespaces[$source->namespace] = true;
            }
        }

        $names = \array_keys($namespaces);
        \sort($names);

        return $names;
    }

    /** @return list<TemplateSource> in resolution order */
    public function all(): array
    {
        $sources = \array_values($this->sources);

        \usort(
            $sources,
            static fn(TemplateSource $a, TemplateSource $b): int
                => [$a->precedence, $a->namespace ?? '', $a->root] <=> [$b->precedence, $b->namespace ?? '', $b->root],
        );

        return $sources;
    }

    /**
     * The directories to look in, highest precedence first.
     *
     * The returned paths already have the namespace folded into them where the
     * override rule applies, so a caller only ever joins a name onto a
     * directory. That also means containment is checked against the override
     * directory rather than the whole theme, so a "../" inside a namespaced
     * name cannot climb out of it.
     *
     * An active theme's directory goes in front of everything, with the same
     * override rule: "customer/profile" looks in templates/admin/dark/ first,
     * "@Billing/invoice" in templates/admin/dark/Billing/. The rest of the
     * order is unchanged, so templates/ is the theme's fallback.
     *
     * @param string|null $theme the active theme's root, from ThemeRegistry
     *
     * @return list<string>
     */
    public function searchPath(?string $namespace, ?string $theme = null): array
    {
        $key = ($theme ?? '') . '|' . ($namespace ?? '');

        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $paths = [];

        if ($theme !== null) {
            $paths[] = $namespace === null ? $theme : Path::join($theme, $namespace);
        }

        foreach ($this->all() as $source) {
            if ($source->namespace === $namespace) {
                $paths[] = $source->root;

                continue;
            }

            // The override rule. Only unnamespaced sources above module
            // precedence take part: a module must not be able to override
            // another module by guessing a directory name.
            if ($namespace !== null
                && $source->namespace === null
                && $source->precedence < TemplateSource::MODULE) {
                $paths[] = Path::join($source->root, $namespace);
            }
        }

        return $this->paths[$key] = $paths;
    }

    public function count(): int
    {
        return \count($this->sources);
    }

    public function clear(): void
    {
        $this->sources = [];
        $this->paths = [];
    }

    /**
     * A different directory claiming the same namespace at the same precedence.
     *
     * Re-registering the identical source is a no-op, so a double boot is
     * harmless; two directories at the same level are refused, because which
     * one won would come down to registration order.
     */
    private function clashingSource(TemplateSource $source): ?TemplateSource
    {
        foreach ($this->sources as $existing) {
            if ($existing->namespace === $source->namespace
                && $existing->precedence === $source->precedence
                && $existing->root !== $source->root) {
                return $existing;
            }
        }

        return null;
    }
}
