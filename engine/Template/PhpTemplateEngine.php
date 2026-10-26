<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * Templates written in PHP.
 *
 * The second engine. Twig is registered first and wins where a directory holds
 * both page.twig and page.php; this one renders everything else ending in .php.
 *
 * There is no syntax to learn, no compiler, no cache directory and no build
 * step, because PHP is already a template language and opcache already compiles
 * it. That is the argument for keeping it: a module that ships PHP views keeps
 * working, and nothing has to be compiled or cached before it runs. The price is that escaping is the author's job -- `$e` -- where in Twig
 * it is the default.
 *
 *     <h1><?= $e($title) ?></h1>
 *     <ul>
 *     <?php foreach ($customers as $customer): ?>
 *         <li><?= $e($customer->name) ?></li>
 *     <?php endforeach ?>
 *     </ul>
 *
 * In scope: every key of $data as a local variable, plus $view and $e. Nothing
 * else — see renderInIsolation() for how that is arranged and why.
 *
 * What this deliberately does not have is inheritance: no @extends, no
 * @section, no @yield. A layout is a template that renders its content as a
 * string and prints it, which is a function call rather than a second control
 * flow to learn, and it is the difference between a template engine and a
 * reimplementation of Blade.
 */
final class PhpTemplateEngine implements TemplateEngine
{
    public function extensions(): array
    {
        return ['php', 'phtml'];
    }

    public function render(TemplateFile $file, array $data, TemplateView $view): string
    {
        $level = \ob_get_level();

        \ob_start();

        try {
            self::renderInIsolation($file->absolutePath, $data, $view, $view->escaper());
        } catch (\Throwable $e) {
            // A template that throws halfway through has already written some
            // markup. Discarding every buffer it opened is what stops that
            // half-page from being glued onto the error response -- and closing
            // down to the level we started at also cleans up any ob_start() the
            // template itself opened and never closed.
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }

            throw TemplateException::renderFailed($file->name, $e);
        }

        return (string) \ob_get_clean();
    }

    /**
     * Include the file with a scope containing the data and nothing else.
     *
     * A static closure, so `$this` does not exist inside a template and an
     * engine's internals cannot be reached from one. The parameters are named
     * with a prefix that no sensible template variable would use, because
     * extract() is about to define arbitrary names in this scope and the file
     * path is the one variable that must survive it.
     *
     * EXTR_SKIP means data can never overwrite $view or $e. A template whose
     * data has a "view" key gets its own $view shadowed by nothing -- the
     * framework's wins, and the value stays reachable as $view->get('view').
     * Silently losing the escaper to a key name would be a security bug with a
     * very long fuse.
     *
     * @param array<string, mixed> $__data
     */
    private static function renderInIsolation(
        string $__path,
        array $__data,
        TemplateView $view,
        Escaper $e,
    ): void {
        \extract($__data, \EXTR_SKIP);

        require $__path;
    }
}
