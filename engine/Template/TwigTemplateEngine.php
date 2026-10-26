<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * Templates written in Twig: the default engine.
 *
 * What Twig buys is automatic escaping -- a value printed without thinking
 * about it is escaped, so a forgotten escape is a visible double-encoding
 * rather than a hole -- and a syntax a designer can be handed without also
 * being handed PHP. What it costs is a dependency and, optionally, a
 * compilation cache. Bootstrap registers it ahead of the PHP engine, so where a
 * directory holds both page.twig and page.php, this one renders.
 *
 * Nothing else in engine/Template/ mentions Twig, and an architecture test
 * keeps it that way: the manager speaks to engines through TemplateEngine, so
 * replacing Twig later is one class and one line in Bootstrap, not a search
 * through the template layer.
 *
 * **The loader mirrors the registry.** Every search path the manager would
 * look in is given to Twig in the same order, and module namespaces become Twig
 * namespaces, so `{% extends "layout.twig" %}` and
 * `{% include "@Billing/row.twig" %}` resolve exactly where the manager
 * would have resolved them — including the override rule. If they did not
 * agree, a template found by one would be missing to the other, which is the
 * sort of bug that takes a day.
 *
 * An active theme is part of that mirror. Its directory goes in front, as it
 * does for the manager, and each theme gets an Environment of its own: a
 * FilesystemLoader remembers what a name resolved to, so one loader shared
 * between themes would hand the second theme the first one's layout.
 */
final class TwigTemplateEngine implements TemplateEngine
{
    /** @var array<string, \Twig\Environment> one per theme root, "" for none */
    private array $twigs = [];

    /** @param string|null $cacheDirectory null disables Twig's compilation cache */
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly ?string $cacheDirectory = null,
        private readonly bool $debug = false,
        /**
         * What the `local` filter calls. Registered on every Environment this
         * builds, so a template compiled into the cache still finds it.
         *
         * @var (\Closure(string, array<array-key, mixed>): string)|null
         */
        private readonly ?\Closure $translator = null,
        /** The filters and functions modules offer; see TemplateHelpers. */
        private readonly TemplateHelpers $helpers = new TemplateHelpers(),
        /** Which theme to search ahead of templates/; see ThemeRegistry. */
        private readonly ?ThemeRegistry $themes = null,
    ) {}

    public function extensions(): array
    {
        // Longest first: profile.html.twig is a Twig template, not a file named
        // "profile.html" that happens to end in something.
        return ['html.twig', 'twig'];
    }

    public function render(TemplateFile $file, array $data, TemplateView $view): string
    {
        try {
            return $this->twig()->render($this->twigName($file), $data + ['view' => $view]);
        } catch (\Throwable $e) {
            throw TemplateException::renderFailed($file->name, $e);
        }
    }

    /**
     * The name Twig should resolve, rather than the path the manager found.
     *
     * Handing Twig the absolute path would work for this one file and break
     * every {% extends %} inside it, because the parent would be looked up
     * against a loader that had never been told where to look.
     */
    private function twigName(TemplateFile $file): string
    {
        return $file->name . '.' . $file->extension;
    }

    /**
     * Built on first render, not in the constructor.
     *
     * Modules register their template directories during the Register stage,
     * so an Environment built at wiring time would have an empty loader. Being
     * lazy also means a request that renders no Twig template -- an API call,
     * an asset -- never builds an Environment at all.
     *
     * The one returned is the active theme's, built the first time that theme
     * renders.
     */
    public function twig(): \Twig\Environment
    {
        $theme = $this->themes?->activeRoot();

        if (isset($this->twigs[$theme ?? ''])) {
            return $this->twigs[$theme ?? ''];
        }

        $loader = new \Twig\Loader\FilesystemLoader();

        foreach ($this->registry->searchPath(null, $theme) as $path) {
            if (\is_dir($path)) {
                $loader->addPath($path);
            }
        }

        foreach ($this->registry->namespaces() as $namespace) {
            foreach ($this->registry->searchPath($namespace, $theme) as $path) {
                if (\is_dir($path)) {
                    $loader->addPath($path, $namespace);
                }
            }
        }

        $twig = new \Twig\Environment($loader, [
            // Twig's default already, stated because it is the reason to
            // choose Twig and should not be switched off by accident.
            'autoescape' => 'html',
            'cache' => $this->cacheDirectory ?? false,
            'debug' => $this->debug,
            // A missing variable is a mistake in the handler, and silently
            // rendering nothing is how it reaches production.
            'strict_variables' => $this->debug,
        ]);

        // {{ 'updated'|local }} and {{ 'user_update_success'|local({user: name}) }}.
        // Not marked is_safe: a translation is text, and it is escaped like
        // every other value -- including the parameters inside it.
        if ($this->translator !== null) {
            $translator = $this->translator;

            $twig->addFilter(new \Twig\TwigFilter(
                'local',
                static fn(string $key, array $parameters = []): string => $translator($key, $parameters),
            ));
        }

        $this->addHelpers($twig);

        return $this->twigs[$theme ?? ''] = $twig;
    }

    /**
     * Give Twig every filter and function the modules offered.
     *
     * The callable is looked up when the template calls it, not now, so an
     * Environment built on a page that never formats money builds no Money.
     *
     * A helper may not replace one of Twig's own (`date`, `raw`, `range`...):
     * Twig would let it, silently, and every template using the built-in would
     * change behaviour. The names are checked against an Environment that has
     * nothing but Twig's defaults, because asking this one would initialise its
     * extensions and refuse every addFilter() after it.
     */
    private function addHelpers(\Twig\Environment $twig): void
    {
        $helpers = $this->helpers;

        if ($helpers->filters() === [] && $helpers->functions() === []) {
            return;
        }

        $builtIn = new \Twig\Environment(new \Twig\Loader\ArrayLoader());

        foreach ($helpers->filters() as $helper) {
            if ($builtIn->getFilter($helper->name) !== null) {
                throw TemplateException::helperShadowsEngine($helper, 'Twig');
            }

            $twig->addFilter(new \Twig\TwigFilter(
                $helper->name,
                static fn(mixed $value, mixed ...$arguments): mixed => $helpers->callable($helper)($value, ...$arguments),
                $helper->safe ? ['is_safe' => ['html']] : [],
            ));
        }

        foreach ($helpers->functions() as $helper) {
            if ($builtIn->getFunction($helper->name) !== null) {
                throw TemplateException::helperShadowsEngine($helper, 'Twig');
            }

            $twig->addFunction(new \Twig\TwigFunction(
                $helper->name,
                static fn(mixed ...$arguments): mixed => $helpers->callable($helper)(...$arguments),
                $helper->safe ? ['is_safe' => ['html']] : [],
            ));
        }
    }

    /** Forget every Environment, so a later registration is picked up. */
    public function flush(): void
    {
        $this->twigs = [];
    }
}
