<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Support\Path;

/**
 * One directory that templates are looked for in.
 *
 * A source has a namespace, a root and a precedence. The precedence is the
 * whole override story: the active template is registered above every module,
 * so a site can replace a module's invoice layout by putting a file in its own
 * theme, without touching the module and without the module having to offer a
 * hook for it.
 */
final class TemplateSource
{
    /**
     * Registered above everything: the active template, and anything an
     * application adds on purpose.
     */
    public const OVERRIDE = 0;

    /** A module's own Templates/ directory. */
    public const MODULE = 100;

    private const NAMESPACE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public readonly string $root;

    /**
     * @param string|null $namespace null for the unnamespaced search path,
     *                               which is what a bare "customer/profile"
     *                               looks in
     */
    public function __construct(
        public readonly ?string $namespace,
        string $root,
        public readonly int $precedence = self::MODULE,
    ) {
        if ($namespace !== null && \preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw TemplateException::unacceptableName(
                $namespace,
                'a namespace appears in a filesystem path, so it must match [A-Za-z0-9][A-Za-z0-9._-]*',
            );
        }

        $this->root = Path::normalize($root);
    }

    public function key(): string
    {
        return ($this->namespace ?? '') . '#' . $this->precedence . '#' . $this->root;
    }

    public function exists(): bool
    {
        return \is_dir($this->root);
    }

    public function describe(): string
    {
        return $this->namespace === null ? 'the application' : '"' . $this->namespace . '"';
    }
}
