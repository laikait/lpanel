<?php

declare(strict_types=1);

namespace App\Engine\Update;

use App\Engine\Error\FrameworkException;

/**
 * A framework update could not be planned, fetched, applied or rolled back.
 *
 * Every message names only paths, versions and repositories -- never the
 * contents of a file or a response body.
 */
final class UpdateException extends FrameworkException
{
    public static function unreadableManifest(string $path, string $why): self
    {
        return new self(\sprintf('%s is not a framework manifest: %s.', $path, $why));
    }

    public static function unsafePath(string $path): self
    {
        return new self(\sprintf(
            'The manifest names "%s", which is outside the application or in a directory an update never touches. '
            . 'Nothing was changed.',
            $path,
        ));
    }

    public static function noBaseline(string $version): self
    {
        return new self(\sprintf(
            'framework.json is missing and the manifest of %s could not be found, so local edits cannot be told '
            . 'from the release. Download lphp-v%1$s.zip and pass --baseline=<its framework.json>.',
            $version,
        ));
    }

    public static function download(string $url, string $why): self
    {
        return new self(\sprintf('Could not download %s: %s.', $url, $why));
    }

    public static function checksum(string $file): self
    {
        return new self(\sprintf(
            '%s does not match its published SHA-256 checksum. It was not unpacked; download it again.',
            $file,
        ));
    }

    public static function notARelease(string $path): self
    {
        return new self(\sprintf('%s is not an LPHP release: it has no framework.json.', $path));
    }

    public static function versionMismatch(string $expected, string $found): self
    {
        return new self(\sprintf('Expected release %s, but its framework.json says %s.', $expected, $found));
    }

    public static function noZip(): self
    {
        return new self('Unpacking a release needs the zip extension (ZipArchive). Enable it, or unpack the zip and pass the directory to --from.');
    }

    public static function downgrade(string $installed, string $target): self
    {
        return new self(\sprintf(
            '%s is older than the installed %s. Pass --force to go back anyway; framework:rollback undoes the last update instead.',
            $target,
            $installed,
        ));
    }

    public static function majorUpgrade(string $installed, string $target): self
    {
        return new self(\sprintf(
            '%s to %s is a major upgrade, which may remove what this application uses. Read UPGRADING.md, then pass --major.',
            $installed,
            $target,
        ));
    }

    public static function unwritable(string $path): self
    {
        return new self(\sprintf('%s could not be written. Nothing after it was changed; framework:rollback restores what was.', $path));
    }

    public static function noBackup(): self
    {
        return new self('There is no framework update to roll back: system/Backups/ holds none.');
    }
}
