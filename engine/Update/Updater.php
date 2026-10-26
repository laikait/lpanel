<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * Plans, applies and rolls back a framework update in an application's directory.
 *
 *     $plan = $updater->plan($installed, $release);
 *     if (!$plan->hasConflicts()) {
 *         $backup = $updater->apply($plan, $release);
 *     }
 *     $updater->rollback();                       // the last one, from its backup
 *
 * **Decided per file, from three hashes:** what the installed release shipped,
 * what the new release ships, and what is on disk. A framework file nobody
 * edited is replaced; one somebody edited is a conflict, and nothing is
 * written while there is one unless the caller forces it. Seed files --
 * modules/, templates/, lang/, config/, public/assets/ -- are the
 * application's: new ones are added, changed ones are written beside the
 * application's copy as <file>.dist, and none is ever replaced. A file in no
 * manifest is the application's and is never looked at.
 *
 * **Everything that changes is backed up first** into
 * system/Backups/framework-<from>-to-<to>-<time>/, with a backup.json saying
 * what was replaced, created and deleted, which is all rollback() needs.
 *
 * **Only built-in functions run while files are written.** The update replaces
 * engine/, which this class was loaded from, and PHP loads classes lazily; so
 * nothing between the first write and the last asks the autoloader for
 * anything.
 */
final class Updater
{
    public const BACKUPS = 'system/Backups';

    private const BACKUP_PREFIX = 'framework-';

    private const BACKUP_FILE = 'backup.json';

    private const ROLLED_BACK = '.rolled-back';

    public function __construct(private readonly string $basePath) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** The installed manifest, or null for an application created before manifests existed. */
    public function installed(): ?Manifest
    {
        $path = $this->path(Manifest::FILE);

        return \is_file($path) ? Manifest::read($path) : null;
    }

    public function plan(Manifest $installed, Release $release): UpdatePlan
    {
        $new = $release->manifest;
        $update = $add = $delete = $seedAdd = $seedChanged = [];
        $conflicts = [];

        $paths = \array_keys($installed->files + $new->files);
        \sort($paths, \SORT_STRING);

        foreach ($paths as $path) {
            $role = $new->role($path) ?? $installed->role($path);

            if ($role === Manifest::MERGED) {
                continue;
            }

            $was = $installed->hash($path);
            $will = $new->hash($path);
            $here = $this->hashOf($path);

            if ($role === Manifest::SEED) {
                if ($will === null) {
                    continue;
                }

                if ($was === null && $here === null) {
                    $seedAdd[] = $path;
                } elseif ($was !== $will && $here !== null && $here !== $will) {
                    $seedChanged[] = $path;
                }

                continue;
            }

            if ($will !== null && $was !== null) {
                if ($will === $was || $here === $will) {
                    continue;
                }

                match (true) {
                    $here === null => $conflicts[$path] = UpdatePlan::DELETED_HERE,
                    $here === $was => $update[] = $path,
                    default => $conflicts[$path] = UpdatePlan::EDITED,
                };
            } elseif ($will !== null) {
                match (true) {
                    $here === null => $add[] = $path,
                    $here === $will => null,
                    default => $conflicts[$path] = UpdatePlan::IN_THE_WAY,
                };
            } elseif ($here !== null) {
                $here === $was ? $delete[] = $path : $conflicts[$path] = UpdatePlan::REMOVED_BUT_EDITED;
            }
        }

        [$composer, $notes] = $this->mergeComposer($installed, $new);

        return new UpdatePlan($installed->version, $new->version, $update, $add, $delete, $conflicts, $seedAdd, $seedChanged, $composer, $notes);
    }

    /**
     * Write the plan. With $force, each conflict is resolved in the release's
     * favour -- the application's copy is in the backup.
     *
     * @return string the backup directory, relative to the application
     *
     * @throws UpdateException when a conflict is left and $force is false, or a file cannot be written
     */
    public function apply(UpdatePlan $plan, Release $release, bool $force = false, ?\DateTimeImmutable $now = null): string
    {
        // Loaded now, from the engine/ that is about to be replaced, rather
        // than half-way through by the autoloader from the new one.
        \class_exists(UpdateException::class);
        \class_exists(ComposerMerge::class);

        if ($plan->hasConflicts() && !$force) {
            throw UpdateException::unwritable(\array_key_first($plan->conflicts) . ' (a conflict; pass --force)');
        }

        $write = [...$plan->update, ...$plan->add];
        $remove = $plan->delete;

        foreach ($plan->conflicts as $path => $why) {
            $why === UpdatePlan::REMOVED_BUT_EDITED ? $remove[] = $path : $write[] = $path;
        }

        $dist = \array_map(static fn(string $path): string => $path . '.dist', $plan->seedChanged);
        $created = [...\array_filter($write, fn(string $p): bool => !\is_file($this->path($p))), ...$plan->seedAdd];
        $replaced = \array_values(\array_filter($write, fn(string $p): bool => \is_file($this->path($p))));
        $replaced[] = Manifest::FILE;

        foreach ($dist as $file) {
            \is_file($this->path($file)) ? $replaced[] = $file : $created[] = $file;
        }

        if ($plan->composer !== null) {
            $replaced[] = 'composer.json';
        }

        $stamp = ($now ?? new \DateTimeImmutable())->format('Ymd-His');
        $backup = self::BACKUPS . '/' . self::BACKUP_PREFIX . $plan->from . '-to-' . $plan->to . '-' . $stamp;
        $record = [
            'from' => $plan->from,
            'to' => $plan->to,
            'created' => \array_values($created),
            'replaced' => \array_values(\array_unique(\array_filter($replaced, fn(string $p): bool => \is_file($this->path($p))))),
            'deleted' => \array_values($remove),
        ];

        // Back up first, all of it: a failure here leaves nothing changed.
        foreach ([...$record['replaced'], ...$record['deleted']] as $path) {
            $this->copy($this->path($path), $this->path($backup . '/files/' . $path));
        }

        $this->put($this->path($backup . '/' . self::BACKUP_FILE), \json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");

        foreach ([...$write, ...$plan->seedAdd] as $path) {
            $this->copy($release->path($path), $this->path($path));
        }

        foreach ($plan->seedChanged as $path) {
            $this->copy($release->path($path), $this->path($path . '.dist'));
        }

        foreach ($remove as $path) {
            $this->remove($path);
        }

        if ($plan->composer !== null) {
            $this->put($this->path('composer.json'), ComposerMerge::encode($plan->composer));
        }

        $this->put($this->path(Manifest::FILE), $release->manifest->toJson());

        return $backup;
    }

    /**
     * Undo the most recent update that has not been rolled back.
     *
     * @return array{from: string, to: string, backup: string}
     *
     * @throws UpdateException when there is none
     */
    public function rollback(): array
    {
        $backup = $this->latestBackup() ?? throw UpdateException::noBackup();
        $json = (string) \file_get_contents($this->path($backup . '/' . self::BACKUP_FILE));

        try {
            /** @var array{from?: mixed, to?: mixed, created?: mixed, replaced?: mixed, deleted?: mixed} $record */
            $record = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UpdateException::unreadableManifest($backup . '/' . self::BACKUP_FILE, 'it is not valid JSON');
        }

        $list = static fn(mixed $paths): array => \is_array($paths) ? \array_values(\array_filter($paths, \is_string(...))) : [];
        $created = $list($record['created'] ?? null);
        $restore = [...$list($record['replaced'] ?? null), ...$list($record['deleted'] ?? null)];

        foreach ([...$created, ...$restore] as $path) {
            Manifest::assertSafe($path);
        }

        foreach ($created as $path) {
            $this->remove($path);
        }

        foreach ($restore as $path) {
            $this->copy($this->path($backup . '/files/' . $path), $this->path($path));
        }

        if (!@\rename($this->path($backup), $this->path($backup . self::ROLLED_BACK))) {
            throw UpdateException::unwritable($backup);
        }

        return [
            'from' => \is_string($record['to'] ?? null) ? $record['to'] : '?',
            'to' => \is_string($record['from'] ?? null) ? $record['from'] : '?',
            'backup' => $backup,
        ];
    }

    /** The newest backup not yet rolled back, relative to the application. */
    public function latestBackup(): ?string
    {
        $found = [];

        foreach (\glob($this->path(self::BACKUPS . '/' . self::BACKUP_PREFIX . '*'), \GLOB_ONLYDIR) ?: [] as $directory) {
            if (!\str_ends_with($directory, self::ROLLED_BACK) && \is_file($directory . '/' . self::BACKUP_FILE)) {
                $found[(string) \filemtime($directory . '/' . self::BACKUP_FILE) . \basename($directory)] = \basename($directory);
            }
        }

        if ($found === []) {
            return null;
        }

        \krsort($found, \SORT_STRING);

        return self::BACKUPS . '/' . \reset($found);
    }

    /** @return array{?array<string, mixed>, list<string>} */
    private function mergeComposer(Manifest $installed, Manifest $new): array
    {
        $path = $this->path('composer.json');

        if ($new->composer === [] || !\is_file($path)) {
            return [null, []];
        }

        $local = \json_decode((string) \file_get_contents($path), true);

        if (!\is_array($local)) {
            return [null, ['composer.json cannot be read, so it was left alone: merge the release\'s by hand.']];
        }

        /** @var array<string, mixed> $local */
        [$merged, $notes] = ComposerMerge::merge($local, $installed->composer, $new->composer);

        return [$merged === $local ? null : $merged, $notes];
    }

    private function hashOf(string $path): ?string
    {
        $full = $this->path($path);

        return \is_file($full) ? (string) \hash_file('sha256', $full) : null;
    }

    private function path(string $relative): string
    {
        return \rtrim($this->basePath, '/\\') . '/' . $relative;
    }

    /** A copy that keeps the mode (laika is executable) and lands whole, through a temporary file. */
    private function copy(string $from, string $to): void
    {
        $directory = \dirname($to);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
            throw UpdateException::unwritable($directory);
        }

        $temporary = $to . '.lphp-update';

        if (!@\copy($from, $temporary) || !@\rename($temporary, $to)) {
            @\unlink($temporary);

            throw UpdateException::unwritable($to);
        }

        $mode = @\fileperms($from);

        if ($mode !== false) {
            @\chmod($to, $mode & 0o777);
        }
    }

    private function put(string $path, string $contents): void
    {
        $directory = \dirname($path);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
            throw UpdateException::unwritable($directory);
        }

        if (@\file_put_contents($path, $contents) === false) {
            throw UpdateException::unwritable($path);
        }
    }

    /** Delete a file, then any directory it leaves empty, up to the application root. */
    private function remove(string $relative): void
    {
        $full = $this->path($relative);

        if (\is_file($full) && !@\unlink($full)) {
            throw UpdateException::unwritable($full);
        }

        $directory = \dirname($relative);

        while ($directory !== '.' && $directory !== '' && @\rmdir($this->path($directory))) {
            $directory = \dirname($directory);
        }
    }
}
