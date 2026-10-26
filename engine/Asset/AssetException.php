<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Error\FrameworkException;

/**
 * Every way the asset layer says no.
 *
 * The refusals worth reading carefully are the ones below the "rejected" line:
 * each of them is a request that got as far as naming a file it should not have
 * been able to name. They carry the logical path the caller asked for and never
 * the absolute path the framework computed -- an attacker probing for a
 * traversal should not be handed the filesystem layout in the error message.
 */
final class AssetException extends FrameworkException
{
    // ---- declaration ------------------------------------------------------

    public static function sourceNeedsName(AssetKind $kind): self
    {
        return new self(\sprintf(
            'An asset source of kind "%s" must be named: there is no single %s, only particular ones.',
            $kind->value,
            $kind->value,
        ));
    }

    public static function sourceCannotBeNamed(AssetKind $kind): self
    {
        return new self(\sprintf(
            'An asset source of kind "%s" cannot be named; there is exactly one.',
            $kind->value,
        ));
    }

    public static function invalidSourceName(string $name): self
    {
        return new self(\sprintf(
            'The asset source name "%s" is not usable. A name appears in a URL and in a filesystem path, '
            . 'so it must match [A-Za-z0-9][A-Za-z0-9_-]* (a template may be "area/theme").',
            $name,
        ));
    }

    public static function unknownSource(AssetKind $kind, ?string $name): self
    {
        return new self(\sprintf(
            'No assets are published for %s. A module publishes assets by having an assets/ directory; '
            . 'anything else is registered explicitly with AssetRegistry::register().',
            $kind->describe($name),
        ));
    }

    public static function duplicateSource(AssetSource $source): self
    {
        return new self(\sprintf(
            'Assets for %s are already published from "%s". Two directories cannot share one asset namespace, '
            . 'because the URL would name both.',
            $source->describe(),
            $source->root,
        ));
    }

    // ---- rejected ---------------------------------------------------------

    /**
     * The path never became a filesystem path at all.
     *
     * Traversal, absolute paths, null bytes, Windows separators and dot-files
     * all land here, before anything touches the disk. The reason is included
     * because the caller is usually a developer who typed "../" by accident,
     * not an attacker.
     */
    public static function unacceptablePath(string $path, string $reason): self
    {
        return new self(\sprintf('The asset path "%s" was rejected: %s.', $path, $reason));
    }

    /**
     * The extension is not on the served list.
     *
     * This is what stops a .php, .phtml, .env, .ini or .sh in a module's
     * assets/ directory from ever being delivered -- not a deny list, which
     * always has one more entry nobody thought of, but an allow list of things
     * a browser asks for.
     */
    public static function unservableType(string $path, string $extension): self
    {
        return new self(\sprintf(
            'The asset "%s" has the extension "%s", which the asset manager does not serve. '
            . 'Executable and configuration files are not assets, and the served list is an allow list, not a deny list.',
            $path,
            $extension === '' ? '(none)' : $extension,
        ));
    }

    /**
     * The file resolved to somewhere outside the published directory.
     *
     * In practice this means a symlink pointing out of the source, because
     * every textual escape was already refused. The message says so without
     * saying where it pointed.
     */
    public static function escapesSource(string $path, AssetSource $source): self
    {
        return new self(\sprintf(
            'The asset "%s" resolves to a location outside the %s asset directory. '
            . 'A link out of a published directory is not a published asset.',
            $path,
            $source->describe(),
        ));
    }

    public static function notFound(string $path, AssetSource $source): self
    {
        return new self(\sprintf('There is no asset "%s" in %s.', $path, $source->describe()));
    }

    public static function unreadable(string $path, AssetSource $source): self
    {
        return new self(\sprintf(
            'The asset "%s" in %s exists but could not be read. Check the file permissions.',
            $path,
            $source->describe(),
        ));
    }

    // ---- manifests --------------------------------------------------------

    public static function unreadableManifest(string $file, string $reason): self
    {
        return new self(\sprintf('The asset manifest "%s" could not be used: %s.', $file, $reason));
    }
}
