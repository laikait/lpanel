<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;

/**
 * Filesystem operations on the server, inside what a policy allows.
 *
 *     $files = new SystemFilesystem(FilesystemPolicy::none()->allowWrite('/srv/backups'));
 *
 *     $files->createDirectory('/srv/backups/2026-09', recursive: true);
 *     $files->write('/srv/backups/2026-09/manifest.json', $json);
 *     $files->inspect('/srv/backups/2026-09/manifest.json')->size;
 *     $files->delete('/srv/backups/2025-01', recursive: true);
 *
 * **Every path goes through FilesystemPolicy::resolve() first**, reading or
 * writing, source and destination. See that class for the rules: absolute
 * paths only, no "..", symbolic links followed and checked, deny by default.
 *
 * This is not the application's storage. It is for the operating system's
 * directories an operations module has to touch -- backups, logs, a web
 * server's configuration -- which is why it is a separate, policy-bound class
 * and not a general file API.
 *
 * **Nothing is replaced unless asked.** write(), copy() and move() refuse an
 * existing destination without overwrite: true, and never replace a directory.
 * write() is atomic: the contents go to a temporary file beside the target and
 * are renamed into place, so a reader sees the old file or the new one, never
 * half of one.
 *
 * **Deleting does not follow links.** delete(recursive: true) removes a
 * symbolic link inside the tree as a link and never descends into what it
 * points at, and an allowed root cannot be deleted or moved through the policy
 * that allows it.
 *
 * Changing permissions or ownership of an existing path is Permission\
 * PermissionManager's, which is privileged and audited separately.
 */
final class SystemFilesystem
{
    /** @param ?SystemAudit $audit when given, every change is recorded (system.filesystem.changed) and every refusal (.refused) */
    public function __construct(
        private readonly FilesystemPolicy $policy,
        private readonly ?SystemAudit $audit = null,
    ) {}

    public function exists(string $path): bool
    {
        $resolved = $this->policy->resolve($path, false);

        return \file_exists($resolved);
    }

    public function inspect(string $path): FileInfo
    {
        $resolved = $this->policy->resolve($path, false);
        $stat = @\stat($resolved);

        if ($stat === false) {
            throw FilesystemException::notFound($path);
        }

        $windows = \PHP_OS_FAMILY === 'Windows';

        return new FileInfo(
            $resolved,
            match (true) {
                \is_dir($resolved) => 'directory',
                \is_file($resolved) => 'file',
                default => 'other',
            },
            \is_dir($resolved) ? 0 : (int) $stat['size'],
            (int) $stat['mtime'],
            (int) $stat['mode'] & 0o777,
            $windows ? null : (int) $stat['uid'],
            $windows ? null : (int) $stat['gid'],
        );
    }

    /** @return list<string> entry names, sorted; not "." or ".." */
    public function list(string $directory): array
    {
        $resolved = $this->policy->resolve($directory, false);

        if (!\is_dir($resolved)) {
            throw \file_exists($resolved) ? FilesystemException::notADirectory($directory) : FilesystemException::notFound($directory);
        }

        $entries = @\scandir($resolved);

        if ($entries === false) {
            throw FilesystemException::failed('list', $directory);
        }

        return \array_values(\array_diff($entries, ['.', '..']));
    }

    /** Creates the directory; a directory that already exists is left as it is. */
    public function createDirectory(string $path, int $permissions = 0o775, bool $recursive = false): void
    {
        $this->audited('create_directory', $path, null, fn() => $this->makeDirectory($path, $permissions, $recursive));
    }

    public function write(string $path, string $contents, bool $overwrite = false): void
    {
        // The size, never the contents.
        $this->audited('write', $path, null, fn() => $this->writeFile($path, $contents, $overwrite), ['bytes' => \strlen($contents)]);
    }

    /** A file, not a directory. */
    public function copy(string $from, string $to, bool $overwrite = false): void
    {
        $this->audited('copy', $to, $from, fn() => $this->copyFile($from, $to, $overwrite));
    }

    /** A file or a directory. The source's root must allow writing too: moving deletes it there. */
    public function move(string $from, string $to, bool $overwrite = false): void
    {
        $this->audited('move', $to, $from, fn() => $this->movePath($from, $to, $overwrite));
    }

    /** A file, a link, or a directory -- empty unless $recursive. */
    public function delete(string $path, bool $recursive = false): void
    {
        $this->audited('delete', $path, null, fn() => $this->deletePath($path, $recursive), ['recursive' => $recursive]);
    }

    /**
     * Run a change, and record how it went: changed, refused by the policy, or
     * failed.
     *
     * @param array<string, string|int|float|bool|null> $context
     */
    private function audited(string $operation, string $target, ?string $source, \Closure $change, array $context = []): void
    {
        $context = ['operation' => $operation, 'source' => $source, ...$context];

        try {
            $change();
        } catch (FilesystemException $e) {
            $this->audit?->record(
                $e->isRefusal() ? 'system.filesystem.refused' : 'system.filesystem.failed',
                $e->isRefusal() ? AuditOutcome::Refused : AuditOutcome::Failed,
                $target,
                $context,
            );

            throw $e;
        }

        $this->audit?->record('system.filesystem.changed', AuditOutcome::Succeeded, $target, $context);
    }

    private function makeDirectory(string $path, int $permissions, bool $recursive): void
    {
        self::checkPermissions($permissions);
        $resolved = $this->policy->resolve($path, true);

        if (\is_dir($resolved)) {
            return;
        }

        if (\file_exists($resolved)) {
            throw FilesystemException::notADirectory($path);
        }

        if (!@\mkdir($resolved, $permissions, $recursive) && !\is_dir($resolved)) {
            throw \is_dir(\dirname($resolved)) ? FilesystemException::failed('create the directory', $path) : FilesystemException::notFound(\dirname($path));
        }
    }

    private function writeFile(string $path, string $contents, bool $overwrite): void
    {
        $resolved = $this->policy->resolve($path, true);
        $this->checkDestination($resolved, $path, $overwrite);

        $directory = \dirname($resolved);

        if (!\is_dir($directory)) {
            throw FilesystemException::notFound(\dirname($path));
        }

        // tempnam() quietly falls back to the system temp directory when it
        // cannot create the file where it was asked; a rename from there would
        // cross filesystems and not be atomic, or leave the contents behind.
        $temporary = @\tempnam($directory, '.write-');

        if ($temporary === false || \strcasecmp(\str_replace('\\', '/', \dirname($temporary)), \str_replace('\\', '/', $directory)) !== 0) {
            if (\is_string($temporary)) {
                @\unlink($temporary);
            }

            throw FilesystemException::failed('write', $path);
        }

        if (@\file_put_contents($temporary, $contents) !== \strlen($contents) || !@\rename($temporary, $resolved)) {
            @\unlink($temporary);

            throw FilesystemException::failed('write', $path);
        }
    }

    private function copyFile(string $from, string $to, bool $overwrite): void
    {
        $source = $this->policy->resolve($from, false);

        if (!\is_file($source)) {
            throw \file_exists($source) ? FilesystemException::notAFile($from) : FilesystemException::notFound($from);
        }

        $destination = $this->policy->resolve($to, true);
        $this->checkDestination($destination, $to, $overwrite);

        if (!@\copy($source, $destination)) {
            throw FilesystemException::failed('copy to', $to);
        }
    }

    private function movePath(string $from, string $to, bool $overwrite): void
    {
        $source = $this->policy->resolve($from, true, followLastLink: false);

        if (!\file_exists($source) && !\is_link($source)) {
            throw FilesystemException::notFound($from);
        }

        if ($this->policy->isRoot($source)) {
            throw FilesystemException::isRoot($from);
        }

        $destination = $this->policy->resolve($to, true);
        $this->checkDestination($destination, $to, $overwrite);

        if (!@\rename($source, $destination)) {
            throw FilesystemException::failed('move to', $to);
        }
    }

    private function deletePath(string $path, bool $recursive): void
    {
        $resolved = $this->policy->resolve($path, true, followLastLink: false);

        if (!\file_exists($resolved) && !\is_link($resolved)) {
            throw FilesystemException::notFound($path);
        }

        if ($this->policy->isRoot($resolved)) {
            throw FilesystemException::isRoot($path);
        }

        if (\is_link($resolved) || !\is_dir($resolved)) {
            self::removeEntry($resolved, $path);

            return;
        }

        $entries = \array_diff(@\scandir($resolved) ?: [], ['.', '..']);

        if ($entries !== [] && !$recursive) {
            throw FilesystemException::notEmpty($path);
        }

        self::removeTree($resolved, $path);
    }

    private function checkDestination(string $resolved, string $path, bool $overwrite): void
    {
        if (\is_dir($resolved) || (\file_exists($resolved) && !$overwrite)) {
            throw FilesystemException::alreadyExists($path);
        }
    }

    /**
     * Depth first, never through a link: a link is removed as a link, whatever
     * it points at. lstat-based checks (is_link first) are what make that true.
     */
    private static function removeTree(string $directory, string $shown): void
    {
        foreach (\array_diff(@\scandir($directory) ?: [], ['.', '..']) as $entry) {
            $child = $directory . '/' . $entry;

            if (\is_link($child) || !\is_dir($child)) {
                self::removeEntry($child, $shown . '/' . $entry);

                continue;
            }

            self::removeTree($child, $shown . '/' . $entry);
        }

        if (!@\rmdir($directory)) {
            throw FilesystemException::failed('delete', $shown);
        }
    }

    /** A file or a link. On Windows a link to a directory is removed with rmdir(). */
    private static function removeEntry(string $path, string $shown): void
    {
        if (!@\unlink($path) && !(\is_dir($path) && @\rmdir($path))) {
            throw FilesystemException::failed('delete', $shown);
        }
    }

    private static function checkPermissions(int $permissions): void
    {
        if ($permissions < 0 || $permissions > 0o777) {
            throw FilesystemException::invalidPermissions($permissions);
        }
    }
}
