<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/**
 * A directory on this machine.
 *
 *     'local' => ['driver' => 'local', 'root' => 'system/Storage'],
 *
 * **Nothing gets out of the root.** Paths are checked by StoragePath, and
 * then the directory a file is in is resolved with realpath() and must still
 * be inside the root -- so a symlink inside the disk that points elsewhere is
 * refused too, not followed.
 *
 * **Writes are atomic**: to a temporary file beside the target, then
 * rename(), so a reader sees the old file or the new one and never half of
 * either.
 *
 * **Temporary URLs** are signed links to the application (the Shared
 * module's "storage.file" route), which streams the file; see Storage.
 */
final class LocalDisk implements Disk
{
    /** Temporary files, while a write is in flight. files() never lists them. */
    private const TEMPORARY = '/^\..+\.[0-9a-f]{16}\.part$/D';

    private readonly string $root;

    /**
     * @param string                              $root          an absolute directory; created on first write
     * @param ?string                             $url           where the root is served publicly, if it is
     * @param ?\Closure(string, int): string       $temporaryUrls path, expiry => a signed URL
     */
    public function __construct(
        string $root,
        private readonly string $name = 'local',
        private readonly ?string $url = null,
        private readonly ?\Closure $temporaryUrls = null,
    ) {
        $this->root = \rtrim($root, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function put(string $path, mixed $contents): void
    {
        $path = StoragePath::local($path);
        $target = $this->root . '/' . $path;
        $directory = \dirname($target);

        if (!\is_dir($this->root) && !@\mkdir($this->root, 0o775, true) && !\is_dir($this->root)) {
            throw StorageException::unwritable($this->name, $path, 'the disk\'s root could not be created.');
        }

        // The nearest directory that already exists is checked BEFORE mkdir():
        // a symlink to /etc inside the disk must not get /etc/new created.
        $existing = $directory;

        while (!\is_dir($existing) && \strlen($existing) > \strlen($this->root)) {
            $existing = \dirname($existing);
        }

        $this->assertInside($existing, $path);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            throw StorageException::unwritable($this->name, $path, 'its directory could not be created.');
        }

        $this->assertInside($directory, $path);

        $temporary = $directory . '/.' . \basename($target) . '.' . \bin2hex(\random_bytes(8)) . '.part';
        $out = @\fopen($temporary, 'xb');

        if ($out === false) {
            throw StorageException::unwritable($this->name, $path, 'a temporary file could not be created.');
        }

        try {
            $copied = \is_string($contents)
                ? \fwrite($out, $contents) === \strlen($contents)
                : \stream_copy_to_stream(Contents::stream($contents), $out) !== false;
            $copied = \fflush($out) && $copied;
        } finally {
            \fclose($out);
        }

        if (!$copied || !@\rename($temporary, $target)) {
            @\unlink($temporary);

            throw StorageException::unwritable($this->name, $path, 'the disk refused the write; is it full?');
        }
    }

    public function get(string $path): string
    {
        $contents = @\file_get_contents($this->existing($path));

        if ($contents === false) {
            throw StorageException::unreadable($this->name, $path, 'it could not be opened.');
        }

        return $contents;
    }

    public function readStream(string $path): mixed
    {
        $stream = @\fopen($this->existing($path), 'rb');

        if ($stream === false) {
            throw StorageException::unreadable($this->name, $path, 'it could not be opened.');
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return $this->resolve($path) !== null;
    }

    public function delete(string $path): bool
    {
        $file = $this->resolve($path);

        return $file !== null && @\unlink($file);
    }

    public function size(string $path): int
    {
        $size = @\filesize($this->existing($path));

        return $size === false ? throw StorageException::unreadable($this->name, $path, 'its size could not be read.') : $size;
    }

    public function lastModified(string $path): int
    {
        $time = @\filemtime($this->existing($path));

        return $time === false ? throw StorageException::unreadable($this->name, $path, 'its time could not be read.') : $time;
    }

    public function files(string $prefix = ''): array
    {
        $prefix = StoragePath::localPrefix($prefix);
        $start = $prefix === '' ? $this->root : $this->root . '/' . $prefix;

        if (!\is_dir($start) || ($prefix !== '' && !$this->isInside($start))) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            // Symlinks are neither listed nor followed: what they point at is
            // not this disk's.
            if ($file->isLink() || !$file->isFile() || \preg_match(self::TEMPORARY, $file->getFilename()) === 1) {
                continue;
            }

            $found[] = \substr(\str_replace('\\', '/', $file->getPathname()), \strlen($this->root) + 1);
        }

        \sort($found, \SORT_STRING);

        return $found;
    }

    public function url(string $path): ?string
    {
        $path = StoragePath::local($path);

        return $this->url === null ? null : \rtrim($this->url, '/') . '/' . MemoryDisk::encode($path);
    }

    public function temporaryUrl(string $path, int $expiresAt): string
    {
        $path = StoragePath::local($path);

        if ($this->temporaryUrls === null) {
            throw StorageException::noTemporaryUrls($this->name, 'it was built without a URL signer.');
        }

        return ($this->temporaryUrls)($path, $expiresAt);
    }

    /** The real path of an existing file inside the root, or null. */
    private function resolve(string $path): ?string
    {
        $candidate = $this->root . '/' . StoragePath::local($path);

        if (\is_link($candidate) || !\is_file($candidate)) {
            return null;
        }

        return $this->isInside($candidate) ? $candidate : null;
    }

    private function existing(string $path): string
    {
        return $this->resolve($path) ?? throw StorageException::notFound($this->name, $path);
    }

    private function isInside(string $path): bool
    {
        $root = \realpath($this->root);
        $real = \realpath($path);

        if ($root === false || $real === false) {
            return false;
        }

        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        $real = \str_replace('\\', '/', $real);

        return $real === $root || \str_starts_with($real, $root . '/');
    }

    private function assertInside(string $directory, string $path): void
    {
        if (!$this->isInside($directory)) {
            throw StorageException::invalidPath($path, 'it leads outside the disk through a symbolic link.');
        }
    }
}
