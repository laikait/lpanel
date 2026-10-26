<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

use App\Engine\Support\Path;

/**
 * Where on the server filesystem the application may read, and where it may write.
 *
 *     $policy = FilesystemPolicy::none()
 *         ->allowRead('/var/log/nginx')
 *         ->allowWrite('/srv/backups');
 *
 * **Denied unless inside an allowed root.** A write root may also be read. The
 * root of a filesystem -- "/" or "C:/" -- cannot be allowed: a policy that
 * allowed it would be no policy.
 *
 * **resolve() is the only way a path becomes a path to act on**, and it is
 * strict on purpose:
 *
 * 1. The path is absolute, with no NUL byte. There is no base directory to be
 *    relative to.
 * 2. **Any ".." segment is refused**, even one that would land back inside a
 *    root. "/srv/backups/../backups/x" is a path somebody built, and the
 *    honest version of it has no "..".
 * 3. The longest part of the path that exists is resolved with realpath(),
 *    which follows every symbolic link in it. What does not exist yet is
 *    appended unchanged -- it cannot contain a link, because it does not exist.
 * 4. That real path must be inside a real root. So a link inside /srv/backups
 *    that points at /etc resolves to /etc, and is refused. A link whose target
 *    is missing cannot be checked, and is refused too.
 *
 * On Windows the comparison ignores case, as the filesystem does.
 *
 * **What this cannot promise:** between resolve() and the operation, another
 * process that can write inside the root could replace a directory with a
 * link. PHP has no openat() to close that gap. A root should be a directory
 * only the application writes to.
 *
 * Immutable: allowRead() and allowWrite() return a new policy.
 */
final class FilesystemPolicy
{
    /** @param array<string, bool> $roots normalised root => writable */
    private function __construct(private readonly array $roots) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function allowRead(string $root): self
    {
        $root = self::checkRoot($root);

        return new self([...$this->roots, $root => $this->roots[$root] ?? false]);
    }

    public function allowWrite(string $root): self
    {
        return new self([...$this->roots, self::checkRoot($root) => true]);
    }

    /** @return array<string, bool> root => writable, for listing */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * The real path to act on, or an exception saying why there is none.
     *
     * With $followLastLink false, only the directory is resolved and the last
     * segment is kept as named: deleting or moving a link acts on the link, not
     * on whatever it points at, wherever that is.
     *
     * @throws FilesystemException
     */
    public function resolve(string $path, bool $forWriting, bool $followLastLink = true): string
    {
        $segments = self::segments($path);

        if (!$followLastLink && \count($segments) > 1) {
            $last = \array_pop($segments);

            return $this->check($path, self::real($path, $segments) . '/' . $last, $forWriting);
        }

        return $this->check($path, self::real($path, $segments), $forWriting);
    }

    /**
     * Steps 3 and 4 of the rules above: the existing part through realpath(),
     * the rest appended.
     *
     * @param list<string> $segments
     */
    private static function real(string $path, array $segments): string
    {
        $existing = $segments;
        $missing = [];

        while ($existing !== [] && !self::exists(self::join($existing))) {
            \array_unshift($missing, \array_pop($existing));
        }

        $base = self::join($existing);
        $real = \realpath($base);

        if ($real === false) {
            throw \is_link($base) ? FilesystemException::danglingLink($path) : FilesystemException::outsidePolicy($path);
        }

        $resolved = \rtrim(Path::normalize($real), '/');

        foreach ($missing as $segment) {
            $resolved .= '/' . $segment;
        }

        return $resolved;
    }

    private function check(string $path, string $resolved, bool $forWriting): string
    {
        $readOnly = false;

        foreach ($this->roots as $root => $writable) {
            $realRoot = \realpath($root);

            if ($realRoot === false || !self::contains(\rtrim(Path::normalize($realRoot), '/'), $resolved)) {
                continue;
            }

            if ($forWriting && !$writable) {
                // Another root may allow writing to the same place.
                $readOnly = true;

                continue;
            }

            return $resolved;
        }

        throw $readOnly ? FilesystemException::readOnly($path) : FilesystemException::outsidePolicy($path);
    }

    /** Whether a resolved path is one of the roots itself. */
    public function isRoot(string $resolved): bool
    {
        foreach (\array_keys($this->roots) as $root) {
            $realRoot = \realpath($root);

            if ($realRoot !== false && self::same(\rtrim(Path::normalize($realRoot), '/'), $resolved)) {
                return true;
            }
        }

        return false;
    }

    private static function checkRoot(string $root): string
    {
        $segments = self::segments($root);

        if ($segments === [] || (\count($segments) === 1 && \preg_match('/^[A-Za-z]:$/D', $segments[0]) === 1)) {
            throw FilesystemException::filesystemRoot($root);
        }

        return self::join($segments);
    }

    /**
     * An absolute path as its segments. The first is a drive ("C:") on
     * Windows; a Unix path's leading "/" is restored by join().
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        if (\str_contains($path, "\0")) {
            throw FilesystemException::nulByte();
        }

        if (!Path::isAbsolute($path)) {
            throw FilesystemException::notAbsolute($path);
        }

        $segments = [];

        foreach (\explode('/', \str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                throw FilesystemException::traversal($path);
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /** @param list<string> $segments */
    private static function join(array $segments): string
    {
        if ($segments !== [] && \preg_match('/^[A-Za-z]:$/D', $segments[0]) === 1) {
            return \count($segments) === 1 ? $segments[0] . '/' : \implode('/', $segments);
        }

        return '/' . \implode('/', $segments);
    }

    private static function exists(string $path): bool
    {
        return \file_exists($path) || \is_link($path);
    }

    private static function contains(string $root, string $path): bool
    {
        return self::same($root, $path) || \str_starts_with(self::fold($path), self::fold($root) . '/');
    }

    private static function same(string $a, string $b): bool
    {
        return self::fold($a) === self::fold($b);
    }

    private static function fold(string $path): string
    {
        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($path) : $path;
    }
}
