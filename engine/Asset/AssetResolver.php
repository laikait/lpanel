<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Support\Path;

/**
 * Turns a logical asset path into a file on disk, or refuses.
 *
 * This is the security boundary of the asset layer, and it is one class on
 * purpose: every path that becomes a file goes through resolve(), so there is
 * exactly one place to read when the question is "can this serve something it
 * should not?".
 *
 * The checks run in this order, and the order matters -- each one is cheaper
 * than the next, and the early ones mean the expensive ones never see hostile
 * input:
 *
 *   1. Syntax.     Null bytes, backslashes, leading slashes, "." and ".."
 *                  segments, empty segments, dot-files. Textual, no disk
 *                  access. This alone stops traversal, because "../" cannot
 *                  survive a rule that every segment must match a whitelist.
 *   2. Extension.  Against the served allow list, so an executable never
 *                  reaches step 3 even if it is sitting in a published
 *                  directory. See MimeTypes.
 *   3. Existence.  is_file() on the joined path.
 *   4. Containment. realpath() on both the file and the published root, then
 *                  a prefix comparison. This is the symlink check: a link
 *                  inside assets/ pointing at /etc/passwd passes steps 1-3
 *                  and fails here.
 *
 * Step 4 is not redundant with step 1. Step 1 stops the path from *saying*
 * anything about the outside; step 4 stops the filesystem from *meaning* it.
 */
final class AssetResolver
{
    /**
     * One path segment.
     *
     * Leading dots are excluded rather than escaped, which is what keeps
     * ".htaccess", ".env" and ".git" out without needing to name them, and what
     * makes ".." unrepresentable rather than merely rejected.
     */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/';

    private const MAX_LENGTH = 1024;

    private const MAX_DEPTH = 16;

    /** @var array<string, AssetReference|null> resolved and refused, per request */
    private array $cache = [];

    /**
     * Resolve, or throw explaining exactly which rule said no.
     *
     * @throws AssetException
     */
    public function resolve(AssetSource $source, string $path): AssetReference
    {
        $logical = $this->normalise($path);
        $key = $source->key() . '|' . $logical;

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?? throw AssetException::notFound($logical, $source);
        }

        $extension = \strtolower(\pathinfo($logical, \PATHINFO_EXTENSION));

        if (!MimeTypes::isServable($extension)) {
            // Not cached: this never depends on the filesystem, so re-deciding
            // it costs nothing and caching a refusal that cannot change is
            // just a way to grow an array.
            throw AssetException::unservableType($logical, $extension);
        }

        $candidate = Path::join($source->root, $logical);

        if (!\is_file($candidate)) {
            $this->cache[$key] = null;

            throw AssetException::notFound($logical, $source);
        }

        if (!Path::within($source->root, $candidate)) {
            // Every textual escape was refused above, so reaching here means
            // the filesystem itself points out of the published directory.
            throw AssetException::escapesSource($logical, $source);
        }

        $real = \realpath($candidate);

        return $this->cache[$key] = new AssetReference(
            $source,
            $logical,
            Path::normalize($real === false ? $candidate : $real),
        );
    }

    /** The same question without the exception, for callers that have a fallback. */
    public function find(AssetSource $source, string $path): ?AssetReference
    {
        try {
            return $this->resolve($source, $path);
        } catch (AssetException) {
            return null;
        }
    }

    public function exists(AssetSource $source, string $path): bool
    {
        return $this->find($source, $path) !== null;
    }

    /**
     * Check the shape of a logical path and return it in canonical form.
     *
     * Nothing here touches the disk, which is deliberate: a hostile path should
     * be refused before the filesystem is asked anything at all.
     */
    public function normalise(string $path): string
    {
        if ($path === '') {
            throw AssetException::unacceptablePath($path, 'it is empty');
        }

        if (\strlen($path) > self::MAX_LENGTH) {
            throw AssetException::unacceptablePath(
                \substr($path, 0, 60) . '...',
                'it is longer than ' . self::MAX_LENGTH . ' characters',
            );
        }

        if (\str_contains($path, "\0")) {
            // A null byte truncates the path inside some C-level filesystem
            // calls, so "app.js\0.php" can mean two different files to two
            // different layers. It is never legitimate.
            throw AssetException::unacceptablePath('(binary)', 'it contains a null byte');
        }

        if (\str_contains($path, '\\')) {
            throw AssetException::unacceptablePath(
                $path,
                'it contains a backslash; asset paths use "/" on every platform',
            );
        }

        if (\str_starts_with($path, '/')) {
            throw AssetException::unacceptablePath($path, 'it is absolute, and asset paths are relative to a source');
        }

        if (\preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw AssetException::unacceptablePath($path, 'it names a drive, and asset paths are relative to a source');
        }

        $segments = \explode('/', $path);

        if (\count($segments) > self::MAX_DEPTH) {
            throw AssetException::unacceptablePath($path, 'it is nested more than ' . self::MAX_DEPTH . ' levels deep');
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw AssetException::unacceptablePath($path, 'it has an empty segment');
            }

            if ($segment === '.' || $segment === '..') {
                throw AssetException::unacceptablePath($path, 'it tries to traverse out of the asset directory');
            }

            if (\preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw AssetException::unacceptablePath(
                    $path,
                    \sprintf('the segment "%s" is not a plain file or directory name', $segment),
                );
            }
        }

        return $path;
    }

    /** Forget everything resolved so far; a long-running worker between jobs. */
    public function flush(): void
    {
        $this->cache = [];
    }
}
