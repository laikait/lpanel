<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/**
 * A disk in memory, for tests: swap it in for "uploads" and assert on what was put.
 *
 *     $container->instance(Storage::class, Storage::fake(['uploads']));
 *
 * Gone when the process ends. Temporary URLs are memory:// placeholders that
 * nothing can fetch, which is enough to assert that one was made.
 */
final class MemoryDisk implements Disk
{
    /** @var array<string, array{string, int}> path => [contents, written at] */
    private array $files = [];

    public function __construct(
        private readonly string $name = 'memory',
        private readonly ?string $url = null,
    ) {}

    public function put(string $path, mixed $contents): void
    {
        $this->files[StoragePath::file($path)] = [Contents::string($contents), \time()];
    }

    public function get(string $path): string
    {
        return $this->file($path)[0];
    }

    public function readStream(string $path): mixed
    {
        return Contents::fromString($this->get($path));
    }

    public function exists(string $path): bool
    {
        return isset($this->files[StoragePath::file($path)]);
    }

    public function delete(string $path): bool
    {
        $path = StoragePath::file($path);

        if (!isset($this->files[$path])) {
            return false;
        }

        unset($this->files[$path]);

        return true;
    }

    public function size(string $path): int
    {
        return \strlen($this->file($path)[0]);
    }

    public function lastModified(string $path): int
    {
        return $this->file($path)[1];
    }

    public function files(string $prefix = ''): array
    {
        $prefix = StoragePath::prefix($prefix);
        $found = [];

        foreach (\array_keys($this->files) as $path) {
            if ($prefix === '' || \str_starts_with($path, $prefix . '/')) {
                $found[] = $path;
            }
        }

        \sort($found, \SORT_STRING);

        return $found;
    }

    public function url(string $path): ?string
    {
        $path = StoragePath::file($path);

        return $this->url === null ? null : \rtrim($this->url, '/') . '/' . self::encode($path);
    }

    public function temporaryUrl(string $path, int $expiresAt): string
    {
        return 'memory://' . $this->name . '/' . self::encode(StoragePath::file($path)) . '?expires=' . $expiresAt;
    }

    /** @return array{string, int} */
    private function file(string $path): array
    {
        return $this->files[StoragePath::file($path)] ?? throw StorageException::notFound($this->name, $path);
    }

    /** Each segment percent-encoded, the slashes kept. */
    public static function encode(string $path): string
    {
        return \implode('/', \array_map(\rawurlencode(...), \explode('/', $path)));
    }
}
