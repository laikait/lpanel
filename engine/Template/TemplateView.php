<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Asset\AssetManager;

/**
 * What a template is handed: $view.
 *
 * A PHP template gets its data as local variables, plus `$view` and `$e`. This
 * is `$view`, and it is deliberately small — four things a template genuinely
 * cannot do without, and nothing else:
 *
 *     <?= $view->render('partials/row', ['customer' => $customer]) ?>
 *     <link rel="stylesheet" href="<?= $view->asset()->core('css/app.css') ?>">
 *     <?php if ($view->has('flash')): ?>…<?php endif ?>
 *     <?= $e($view->get('title', 'Customers')) ?>
 *
 * What it is not is a handle on the container. A template that can resolve
 * arbitrary services is a template that can run a query, and then the question
 * "what does this page do" stops having an answer you can read in the handler.
 * If a template needs something, the handler passes it in.
 *
 * It is also the reason a template never reaches for a global helper: $view is
 * already in scope, so there is nothing to reach for.
 */
final class TemplateView
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly AssetManager $assets,
        private readonly Escaper $escaper,
        private readonly array $data = [],
    ) {}

    /**
     * Render another template, inheriting nothing.
     *
     * A partial gets exactly the data it is given, because a partial that could
     * see its parent's variables is a partial whose contract is "whatever
     * happened to be in scope". Pass what it needs.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        return $this->templates->render($name, $data);
    }

    public function exists(string $name): bool
    {
        return $this->templates->exists($name);
    }

    /**
     * Asset URLs.
     *
     * Templates are the main reason the asset layer exists: a stylesheet URL
     * belongs in the markup that needs it, and it must not say where the file
     * lives on disk.
     */
    public function asset(): AssetManager
    {
        return $this->assets;
    }

    public function escaper(): Escaper
    {
        return $this->escaper;
    }

    /**
     * The translation of $key, as plain text: escape it like any other value.
     *
     *     <?= $view->escaper()->html($view->local('user_update_success', ['user' => $name])) ?>
     *
     * The Twig equivalent is {{ 'user_update_success'|local({user: name}) }},
     * which Twig escapes on its own.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function local(string $key, array $parameters = []): string
    {
        return $this->templates->translate($key, $parameters);
    }

    /**
     * Apply a filter some module offers: `{{ total|money }}` in Twig.
     *
     *     <?= $e($view->filter('money', $invoice->total)) ?>
     *
     * The result is whatever the helper returns, unescaped; a PHP template
     * escapes it like any other value. Only names a module declared in its
     * module.php can be called -- see TemplateHelpers for why that is not a
     * way into the container.
     */
    public function filter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        return $this->templates->helpers()->applyFilter($name, $value, ...$arguments);
    }

    /**
     * Call a function some module offers: `{{ route('home') }}` in Twig.
     *
     *     <a href="<?= $e->attr($view->call('route', 'home')) ?>">
     */
    public function call(string $name, mixed ...$arguments): mixed
    {
        return $this->templates->helpers()->callFunction($name, ...$arguments);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The same view over different data, for rendering a partial.
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        return new self($this->templates, $this->assets, $this->escaper, $data);
    }
}
