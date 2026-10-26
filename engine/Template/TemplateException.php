<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Error\FrameworkException;

final class TemplateException extends FrameworkException
{
    /**
     * Nothing in the search path had that name.
     *
     * The message lists where it looked, in the order it looked, because "not
     * found" on its own is the least useful thing a template system can say --
     * the answer is almost always that the file is one directory over, or that
     * the namespace was spelled differently.
     *
     * @param list<string> $searched
     * @param list<string> $extensions
     */
    public static function notFound(string $name, array $searched, array $extensions): self
    {
        return new self(\sprintf(
            'No template named "%s" was found. Tried %s in: %s.',
            $name,
            \implode(', ', \array_map(static fn(string $e): string => '.' . $e, $extensions)),
            $searched === [] ? '(nothing is registered)' : \implode(', ', $searched),
        ));
    }

    public static function unknownNamespace(string $namespace, string $name): self
    {
        return new self(\sprintf(
            'The template "%s" asks for the "%s" namespace, which nothing has registered. '
            . 'A module publishes one by having a Templates/ directory.',
            $name,
            $namespace,
        ));
    }

    /**
     * The name could not become a path.
     *
     * Template names reach this from application code rather than from a
     * request, but "rather than" is not "never": a name assembled from a route
     * parameter is one refactor away in any application, so the check is here
     * rather than in a comment saying it is not needed.
     */
    public static function unacceptableName(string $name, string $reason): self
    {
        return new self(\sprintf('The template name "%s" was rejected: %s.', $name, $reason));
    }

    /**
     * The name resolved to a file outside every directory it was allowed to
     * look in -- in practice, a symlink out of a template directory.
     */
    public static function escapesSearchPath(string $name): self
    {
        return new self(\sprintf(
            'The template "%s" resolves to a location outside its template directory.',
            $name,
        ));
    }

    public static function noEngineFor(string $extension): self
    {
        return new self(\sprintf(
            'No template engine handles ".%s". Register one with TemplateManager::addEngine().',
            $extension,
        ));
    }

    public static function duplicateExtension(string $extension, string $engine): self
    {
        return new self(\sprintf(
            'The ".%s" extension is already handled by %s. One extension, one engine.',
            $extension,
            $engine,
        ));
    }

    public static function duplicateNamespace(TemplateSource $existing): self
    {
        return new self(\sprintf(
            'Templates for %s are already registered from "%s". '
            . 'Two directories cannot share one namespace at the same precedence.',
            $existing->describe(),
            $existing->root,
        ));
    }

    /**
     * Withheld: whatever the template threw is quoted here, and a template is
     * application code that can throw anything at all.
     */
    public static function renderFailed(string $name, \Throwable $previous): self
    {
        return (new self(
            \sprintf('Rendering "%s" failed: %s', $name, $previous->getMessage()),
            0,
            $previous,
        ))->withheld();
    }

    // ---- template helpers -------------------------------------------------

    public static function unacceptableHelperName(string $kind, string $name, string $module): self
    {
        return new self(\sprintf(
            'Module "%s" offers a template %s named "%s". A helper name is lower case letters, digits and '
            . 'underscores, starting with a letter or underscore, so Twig and PHP templates can both spell it.',
            $module,
            $kind,
            $name,
        ));
    }

    public static function reservedHelperName(string $kind, string $name, string $module): self
    {
        return new self(\sprintf(
            'Module "%s" offers a template %s named "%s", which the engine already provides to every template. '
            . 'Choose another name.',
            $module,
            $kind,
            $name,
        ));
    }

    public static function duplicateHelper(string $kind, string $name, string $first, string $second): self
    {
        return new self(\sprintf(
            'Modules "%s" and "%s" both offer a template %s named "%s". Names are unique across the application, '
            . 'or which one a template got would depend on module order.',
            $first,
            $second,
            $kind,
            $name,
        ));
    }

    public static function unknownHelper(string $kind, string $name): self
    {
        return new self(\sprintf(
            'No module offers a template %s named "%s". A module declares one in module.php with '
            . '$module->templates(...).',
            $kind,
            $name,
        ));
    }

    public static function helperNotCallable(TemplateHelper $helper, string $reason): self
    {
        return new self(\sprintf(
            'The template %s "%s" offered by module "%s" cannot be called (%s): %s.',
            $helper->kind,
            $helper->name,
            $helper->module,
            $helper->describe(),
            $reason,
        ));
    }

    /** The helper would replace something the template engine itself provides. */
    public static function helperShadowsEngine(TemplateHelper $helper, string $engine): self
    {
        return new self(\sprintf(
            'Module "%s" offers a template %s named "%s", which %s already provides. '
            . 'Replacing it would change every template that uses it; choose another name.',
            $helper->module,
            $helper->kind,
            $helper->name,
            $engine,
        ));
    }

    // ---- themes -------------------------------------------------------------

    public static function invalidThemeName(string $theme, string $reason): self
    {
        return new self(\sprintf(
            'The theme name "%s" is not usable: %s. A theme is "theme" or "area/theme", '
            . 'each part matching [A-Za-z0-9][A-Za-z0-9_-]*.',
            $theme,
            $reason,
        ));
    }

    public static function unknownTheme(string $theme): self
    {
        return new self(\sprintf(
            'No theme "%s" is registered. Themes are never discovered: list it in templates.themes, '
            . 'return it from the templates.theme_provider class, or call ThemeRegistry::register().',
            $theme,
        ));
    }

    public static function invalidErrorThemePrefix(string $prefix): self
    {
        return new self(\sprintf(
            'The error theme prefix "%s" is not a path. It must start with "/", like "/admin".',
            $prefix,
        ));
    }

    public static function invalidThemeProvider(string $class): self
    {
        return new self(\sprintf(
            'templates.theme_provider names "%s", which does not implement %s.',
            $class,
            ThemeProvider::class,
        ));
    }
}
