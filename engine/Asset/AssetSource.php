<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Support\Path;

/**
 * One published asset namespace: a logical name bound to a directory.
 *
 * A source is the entire mapping between the public URL space and the disk. It
 * is deliberately a value rather than a service: registering one publishes a
 * directory, and that is an act somebody should be able to read in a diff.
 *
 *     core                 ->  <base>/assets
 *     template             ->  <base>/templates/assets
 *     template + "dark"        ->  <base>/templates/dark/assets
 *     template + "admin/dark"  ->  <base>/templates/admin/dark/assets
 *     module  + "Billing"      ->  <base>/modules/Billing/assets
 *
 * A template name may have two segments, an area and a theme; nothing else
 * may. ThemeRegistry is what publishes named templates.
 *
 * The root is stored normalised but NOT resolved: a source may legitimately be
 * registered for a directory that does not exist yet (a template that ships no
 * assets, a module mid-installation). Resolution is where existence matters,
 * and that is AssetResolver's job, not this one's.
 */
final class AssetSource
{
    /** Names appear in URLs and in paths, so they are checked, not trusted. */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    /** A template's "theme" or "area/theme": one slash at most, never at an end. */
    private const TEMPLATE_NAME_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9_-]*(/[A-Za-z0-9][A-Za-z0-9_-]*)?$#';

    public readonly string $root;

    public function __construct(
        public readonly AssetKind $kind,
        public readonly ?string $name,
        string $root,
    ) {
        if ($name !== null) {
            if (!$kind->allowsName()) {
                throw AssetException::sourceCannotBeNamed($kind);
            }

            $pattern = $kind === AssetKind::Template ? self::TEMPLATE_NAME_PATTERN : self::NAME_PATTERN;

            if (\preg_match($pattern, $name) !== 1) {
                throw AssetException::invalidSourceName($name);
            }
        } elseif ($kind->requiresName()) {
            throw AssetException::sourceNeedsName($kind);
        }

        $this->root = Path::normalize($root);
    }

    /**
     * The registry key: "core", "template", "template/admin", "module/Billing".
     *
     * This is also exactly the URL prefix after /assets/, which is not a
     * coincidence -- one string means the URL scheme and the lookup key can
     * never drift apart.
     */
    public function key(): string
    {
        return $this->name === null ? $this->kind->value : $this->kind->value . '/' . $this->name;
    }

    /** Whether the published directory is actually there. */
    public function exists(): bool
    {
        return \is_dir($this->root);
    }

    public function describe(): string
    {
        return $this->kind->describe($this->name);
    }
}
