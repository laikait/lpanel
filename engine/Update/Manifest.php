<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * What a framework release shipped: every file, its SHA-256, and whose it is.
 *
 *     {
 *       "version": "3.1.0",
 *       "composer": { ...the release's composer.json... },
 *       "files": {
 *         "engine/Http/Request.php": {"sha256": "...", "role": "owned"},
 *         "modules/Shared/module.php": {"sha256": "...", "role": "seed"},
 *         "composer.json": {"sha256": "...", "role": "merged"}
 *       }
 *     }
 *
 * An application is a copy of this repository, so framework files and the
 * application's own sit side by side -- even in one directory: the
 * application's tests are in tests/Unit next to the framework's. Ownership is
 * therefore recorded per file, and the hash is what lets an update tell a
 * framework file nobody touched (safe to replace) from one somebody patched.
 *
 * - owned:  the framework's. Replaced, added and deleted by an update.
 * - seed:   shipped once, the application's from then on: modules/, templates/,
 *           lang/, config/, public/assets/. Added when new; never replaced.
 * - merged: composer.json, merged key by key with the application's own.
 *
 * A file in no manifest is the application's and is never touched.
 *
 * This is a persisted format: a release must read what the previous one wrote.
 */
final class Manifest
{
    public const FILE = 'framework.json';

    public const OWNED = 'owned';

    public const SEED = 'seed';

    public const MERGED = 'merged';

    /** Directories whose files are the application's once installed. */
    public const SEED_DIRECTORIES = ['modules/', 'templates/', 'lang/', 'config/', 'public/assets/'];

    public const MERGED_FILES = ['composer.json'];

    /**
     * Where an update never writes, whatever a manifest says: dependencies,
     * runtime state, secrets and version control.
     */
    public const NEVER = ['vendor/', 'system/', '.git/'];

    /** Files an update never writes: the application's secrets. .env.example is the framework's. */
    public const NEVER_FILES = ['.env', '.env.local'];

    /**
     * @param array<string, array{sha256: string, role: string}> $files
     * @param array<string, mixed>                               $composer
     */
    public function __construct(
        public readonly string $version,
        public readonly array $files,
        public readonly array $composer = [],
    ) {}

    /**
     * Build the manifest of a release from its unpacked tree, as `git archive` produced it.
     *
     * @throws UpdateException for a path an update would refuse
     */
    public static function build(string $root, string $version): self
    {
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = \substr(\str_replace('\\', '/', $file->getPathname()), \strlen($root) + 1);

            // The manifest itself, and what a release never ships: system/ holds
            // only .gitignore placeholders, which every application already has.
            if ($path === self::FILE || self::untouchable($path)) {
                continue;
            }

            self::assertSafe($path);
            $files[$path] = ['sha256' => (string) \hash_file('sha256', $file->getPathname()), 'role' => self::roleOf($path)];
        }

        \ksort($files, \SORT_STRING);

        $composer = \is_file($root . '/composer.json') ? self::decode((string) \file_get_contents($root . '/composer.json'), $root . '/composer.json') : [];

        return new self($version, $files, $composer);
    }

    /**
     * @throws UpdateException when the file is missing or malformed
     */
    public static function read(string $path): self
    {
        $contents = @\file_get_contents($path);

        if ($contents === false) {
            throw UpdateException::unreadableManifest($path, 'it cannot be read');
        }

        return self::fromJson($contents, $path);
    }

    /**
     * @throws UpdateException
     */
    public static function fromJson(string $json, string $source = Manifest::FILE): self
    {
        $data = self::decode($json, $source);
        $version = $data['version'] ?? null;
        $files = $data['files'] ?? null;
        $composer = $data['composer'] ?? [];

        if (!\is_string($version) || $version === '' || !\is_array($files) || !\is_array($composer)) {
            throw UpdateException::unreadableManifest($source, 'it needs "version", "files" and "composer"');
        }

        $read = [];

        foreach ($files as $path => $entry) {
            $sha = \is_array($entry) ? ($entry['sha256'] ?? null) : null;
            $role = \is_array($entry) ? ($entry['role'] ?? null) : null;

            if (!\is_string($path) || !\is_string($sha) || \preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1
                || !\in_array($role, [self::OWNED, self::SEED, self::MERGED], true)) {
                throw UpdateException::unreadableManifest($source, \sprintf('the entry for "%s" is malformed', (string) $path));
            }

            self::assertSafe($path);
            $read[$path] = ['sha256' => $sha, 'role' => $role];
        }

        /** @var array<string, mixed> $composer */
        return new self($version, $read, $composer);
    }

    public function toJson(): string
    {
        return \json_encode(
            ['version' => $this->version, 'composer' => $this->composer === [] ? new \stdClass() : $this->composer, 'files' => $this->files],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public function write(string $path): void
    {
        if (@\file_put_contents($path, $this->toJson()) === false) {
            throw UpdateException::unwritable($path);
        }
    }

    public function has(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function hash(string $path): ?string
    {
        return $this->files[$path]['sha256'] ?? null;
    }

    public function role(string $path): ?string
    {
        return $this->files[$path]['role'] ?? null;
    }

    /** Whose a path is when a release ships it. */
    public static function roleOf(string $path): string
    {
        if (\in_array($path, self::MERGED_FILES, true)) {
            return self::MERGED;
        }

        foreach (self::SEED_DIRECTORIES as $directory) {
            if (\str_starts_with($path, $directory)) {
                return self::SEED;
            }
        }

        return self::OWNED;
    }

    /**
     * A relative path inside the application, outside the directories an update never touches.
     *
     * @throws UpdateException
     */
    public static function assertSafe(string $path): void
    {
        $segments = \explode('/', $path);

        if ($path === '' || \str_starts_with($path, '/') || \str_contains($path, '\\') || \str_contains($path, "\0")
            || \preg_match('/^[A-Za-z]:/', $path) === 1
            || \in_array('..', $segments, true) || \in_array('.', $segments, true) || \in_array('', $segments, true)) {
            throw UpdateException::unsafePath($path);
        }

        if (self::untouchable($path)) {
            throw UpdateException::unsafePath($path);
        }
    }

    /** Whether $path is in a place no update writes: vendor/, system/, .git/ or a .env file. */
    public static function untouchable(string $path): bool
    {
        if (\in_array($path, self::NEVER_FILES, true)) {
            return true;
        }

        foreach (self::NEVER as $never) {
            if ($path === \rtrim($never, '/') || \str_starts_with($path, $never)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json, string $source): array
    {
        try {
            $data = \json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UpdateException::unreadableManifest($source, 'it is not valid JSON');
        }

        if (!\is_array($data)) {
            throw UpdateException::unreadableManifest($source, 'it is not a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
