<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetSource;
use App\Engine\Support\Path;

/**
 * Which themes exist, and which one this request renders with.
 *
 * A theme is a directory under templates/ holding views and an assets/ folder:
 *
 *     templates/dark/                 "dark"
 *     templates/admin/dark/           "admin/dark", an area and a theme
 *
 * **Themes are registered, never discovered.** Which themes exist is something
 * an application decides -- in templates.themes, or in a ThemeProvider that
 * reads the database -- and a directory appearing under templates/ publishes
 * nothing on its own. Registering one does two things: its assets/ becomes the
 * asset source "template/<name>", and its directory becomes a view root that
 * use() can put in front of templates/.
 *
 * Assets are always named: asset()->template('admin/dark', 'css/app.css') reads
 * templates/admin/dark/assets/ and nothing else. Views follow the active theme
 * and fall back to templates/, because a layout and its includes cannot each be
 * told which theme they belong to.
 *
 * **What activates one.** A route or a group declares meta(['theme' => ...]),
 * which a bootstrap listener hands to use() before the guards run, so a 403 is
 * themed too. An error raised before any route matched -- an unknown URL, a
 * 405, maintenance -- has no route to ask, and forPath() answers from the
 * templates.error_themes prefix map instead.
 */
final class ThemeRegistry
{
    /** The route meta key: ->meta(['theme' => 'admin/dark']). */
    public const META = 'theme';

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    /**
     * Folders of templates/ itself that belong to no theme, so no theme can be
     * called after them: the site's static files, the emails (which never
     * follow a theme; see Mailer::render()) and the base error pages.
     */
    private const RESERVED = ['assets', 'emails', 'errors'];

    /** @var array<string, string> name => view root */
    private array $themes = [];

    /** @var array<string, string> path prefix => theme, longest first once sorted */
    private array $errorThemes = [];

    private bool $errorThemesSorted = true;

    /**
     * First segments no theme may take, case-insensitively: module ids, since
     * templates/<Module>/ is already that module's override directory.
     *
     * @var array<string, true>
     */
    private array $reserved = [];

    private ?string $active = null;

    private bool $loaded = false;

    private readonly string $root;

    /**
     * @param string                          $root   the templates/ directory
     * @param (\Closure(self): void)|null     $loader fills the registry from
     *                                                configuration; run once,
     *                                                by load()
     */
    public function __construct(
        private readonly AssetRegistry $assets,
        string $root,
        private readonly ?\Closure $loader = null,
    ) {
        $this->root = Path::normalize($root);
    }

    /**
     * Run the loader, once.
     *
     * Explicit rather than on construction, because the loader may need
     * services -- a provider reading the database -- that exist only once the
     * container is complete, and module ids that exist only after discovery.
     * Application calls it at the point both are true; everything here that
     * reads the registry calls it too, so a caller with no Application still
     * sees configured themes.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if ($this->loader !== null) {
            ($this->loader)($this);
        }
    }

    /**
     * Forbid themes whose first segment is one of these; module ids, in practice.
     *
     * @param iterable<string> $names
     */
    public function reserve(iterable $names): void
    {
        foreach ($names as $name) {
            $this->reserved[\strtolower($name)] = true;
        }

        foreach (\array_keys($this->themes) as $theme) {
            $this->assertUnreserved($theme);
        }
    }

    /**
     * A theme name as it is stored: slashes at either end trimmed.
     *
     * "/app/default/", "app/default/" and "app/default" are one theme, because
     * a name written like a path -- in route meta, in config, from a database --
     * should not need to be spelled exactly. Only the ends: "app//default" is
     * still refused, since an empty segment is not a directory.
     */
    public static function normalise(string $theme): string
    {
        return \trim($theme, '/');
    }

    /** Publish a theme's assets and make its views available to use(). Idempotent. */
    public function register(string $theme): void
    {
        $theme = self::normalise($theme);
        $this->assertUsable($theme);

        if (isset($this->themes[$theme])) {
            return;
        }

        $root = Path::join($this->root, $theme);

        $this->assets->register(new AssetSource(AssetKind::Template, $theme, Path::join($root, 'assets')));
        $this->themes[$theme] = $root;
    }

    /** Use $theme for error pages under $prefix ("/admin") when no route chose one. */
    public function mapErrors(string $prefix, string $theme): void
    {
        if (!\str_starts_with($prefix, '/')) {
            throw TemplateException::invalidErrorThemePrefix($prefix);
        }

        $theme = self::normalise($theme);

        if (!isset($this->themes[$theme])) {
            throw TemplateException::unknownTheme($theme);
        }

        $this->errorThemes[self::normalisePath($prefix)] = $theme;
        $this->errorThemesSorted = false;
    }

    public function has(string $theme): bool
    {
        $this->load();

        return isset($this->themes[self::normalise($theme)]);
    }

    /** @return list<string> every registered theme, sorted */
    public function all(): array
    {
        $this->load();

        $themes = \array_keys($this->themes);
        \sort($themes);

        return $themes;
    }

    /** The view root of a registered theme: templates/admin/dark. */
    public function root(string $theme): string
    {
        $this->load();

        return $this->themes[self::normalise($theme)] ?? throw TemplateException::unknownTheme($theme);
    }

    /** Render with $theme from now on; null goes back to templates/ alone. */
    public function use(?string $theme): void
    {
        if ($theme !== null && !$this->has($theme)) {
            throw TemplateException::unknownTheme($theme);
        }

        $this->active = $theme === null ? null : self::normalise($theme);
    }

    public function active(): ?string
    {
        return $this->active;
    }

    /** The active theme's view root, or null when none is active. */
    public function activeRoot(): ?string
    {
        return $this->active === null ? null : $this->themes[$this->active];
    }

    /**
     * The error theme for a request path, by its longest matching prefix.
     *
     * A prefix matches whole segments: "/admin" is "/admin" and "/admin/x",
     * never "/administrator". "/" matches everything.
     */
    public function forPath(string $path): ?string
    {
        $this->load();

        if (!$this->errorThemesSorted) {
            \uksort($this->errorThemes, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
            $this->errorThemesSorted = true;
        }

        $path = self::normalisePath($path);

        foreach ($this->errorThemes as $prefix => $theme) {
            if ($prefix === '/' || $path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return $theme;
            }
        }

        return null;
    }

    /** @return array<string, string> path prefix => theme */
    public function errorThemes(): array
    {
        $this->load();

        return $this->errorThemes;
    }

    private function assertUsable(string $theme): void
    {
        $segments = \explode('/', $theme);

        if (\count($segments) > 2) {
            throw TemplateException::invalidThemeName($theme, 'it has more than two parts');
        }

        foreach ($segments as $segment) {
            if (\preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw TemplateException::invalidThemeName(
                    $theme,
                    \sprintf('"%s" is not a plain directory name', $segment),
                );
            }
        }

        if (\in_array(\strtolower($segments[0]), self::RESERVED, true)) {
            throw TemplateException::invalidThemeName(
                $theme,
                \sprintf('templates/%s/ belongs to no theme', \strtolower($segments[0])),
            );
        }

        $this->assertUnreserved($theme);
    }

    private function assertUnreserved(string $theme): void
    {
        $first = \strtolower(\explode('/', $theme, 2)[0]);

        if (isset($this->reserved[$first])) {
            throw TemplateException::invalidThemeName(
                $theme,
                \sprintf('templates/%s/ is already the override directory of the module of that name', \explode('/', $theme, 2)[0]),
            );
        }
    }

    private static function normalisePath(string $path): string
    {
        $path = '/' . \trim($path, '/');

        return (string) \preg_replace('#/{2,}#', '/', $path);
    }
}
