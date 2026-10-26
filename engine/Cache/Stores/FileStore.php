<?php

declare(strict_types=1);

namespace App\Engine\Cache\Stores;

use App\Engine\Cache\AtomicStore;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheEntry;
use App\Engine\Cache\CacheException;
use App\Engine\Cache\PrunableStore;
use App\Engine\Support\Path;
use App\Engine\Support\Sealer;

/**
 * Files under system/Cache/data, one per entry.
 *
 * The store that works everywhere: no extension, no daemon, no credentials. For
 * an application on one machine it is also usually the right one -- the disk is
 * the same disk the code is on, and the operating system's page cache means a
 * hot entry is not really a disk read at all.
 *
 * Serialised rather than var_export()ed, which is the opposite of the choice
 * the configuration and module caches make, and for a reason worth writing
 * down. Those hold plain data that changes at deploy time, so var_export plus
 * opcache is ideal. This holds arbitrary values that change constantly, and
 * opcache would be actively wrong for it: it would either hold a stale entry
 * whose file has already been rewritten, or spend its budget invalidating
 * bytecode for files that are not code.
 *
 * The filename is a hash of the key, not the key. Keys are already restricted
 * to safe characters, but "Customers" and "customers" are the same file on
 * Windows, an eighty-character key is a fine key and a poor filename, and a
 * hash sidesteps all of it. The key itself is stored inside the file, so
 * looking at the directory is still possible when something has gone wrong.
 *
 * The prefix directory is what makes flushing a namespace cheap: everything
 * under "billing" lives in one subdirectory, so clearing it is removing that
 * directory rather than reading every entry in the cache to see whose it is.
 */
final class FileStore implements PrunableStore, AtomicStore
{
    public const EXTENSION = '.cache';

    /** Entries whose key has no namespace. A name no namespace can take. */
    public const UNNAMESPACED = '_';

    public function __construct(
        private readonly string $directory,
        private readonly int $permissions = 0o775,
        private readonly Sealer $sealer = new Sealer(),
    ) {}

    public function describe(): string
    {
        return 'file ' . $this->directory;
    }

    public function get(string $key): ?CacheEntry
    {
        $file = $this->pathFor($key);

        if (!\is_file($file)) {
            return null;
        }

        $contents = @\file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        $entry = $this->decode($contents);

        if ($entry === null) {
            // Unreadable or written by an older format. A cache file nobody can
            // read is a miss, and leaving it would make it a permanent one.
            @\unlink($file);

            return null;
        }

        if ($entry->hasExpired()) {
            // Expired entries delete themselves on the way past, so anything
            // still being asked for cleans up without a sweep.
            @\unlink($file);

            return null;
        }

        return $entry;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->pathFor($key);
        $directory = \dirname($file);

        if (!\is_dir($directory) && !@\mkdir($directory, $this->permissions, true) && !\is_dir($directory)) {
            return false;
        }

        $payload = $this->encode($key, $value, $ttl === null ? null : \time() + $ttl);

        // Written and renamed, so a concurrent reader never sees half an entry.
        $temporary = $file . '.' . \getmypid() . '.tmp';

        if (@\file_put_contents($temporary, $payload, \LOCK_EX) === false) {
            return false;
        }

        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    public function forget(string $key): bool
    {
        $file = $this->pathFor($key);

        return !\is_file($file) || @\unlink($file);
    }

    /**
     * Under an exclusive flock() on a companion .lock file, so the look and
     * the write are one step for every process on this machine.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->exclusively($key, fn(): bool => $this->get($key) === null && $this->put($key, $value, $ttl));
    }

    public function forgetIf(string $key, mixed $value): bool
    {
        return $this->exclusively($key, function () use ($key, $value): bool {
            $entry = $this->get($key);

            return $entry !== null && $entry->value === $value && $this->forget($key);
        });
    }

    /** @param \Closure(): bool $work */
    private function exclusively(string $key, \Closure $work): bool
    {
        $file = $this->pathFor($key);
        $directory = \dirname($file);

        if (!\is_dir($directory) && !@\mkdir($directory, $this->permissions, true) && !\is_dir($directory)) {
            return false;
        }

        $handle = @\fopen($file . '.lock', 'c');

        if ($handle === false) {
            return false;
        }

        try {
            return \flock($handle, \LOCK_EX) && $work();
        } finally {
            \flock($handle, \LOCK_UN);
            \fclose($handle);
        }
    }

    public function flush(string $prefix = ''): bool
    {
        if (!\is_dir($this->directory)) {
            return true;
        }

        // A namespace is a directory, so this removes one rather than reading
        // every entry to find out whose it is.
        $target = $prefix === ''
            ? $this->directory
            : Path::join($this->directory, $this->folderFor($prefix));

        if (!Path::within($this->directory, $target)) {
            return false;
        }

        if (!\is_dir($target)) {
            return true;
        }

        $this->removeUnder($target, $target !== $this->directory);

        return true;
    }

    /**
     * Delete entries that have expired.
     *
     * Reading an expired entry already removes it, so this is for the ones
     * nobody asks for again -- a key that included yesterday's date, a
     * namespace a module stopped using. It is a maintenance job rather than
     * something a request should ever do, which is why it is a command
     * (cache:clear --expired) and not a probability check on every write.
     *
     * @return int how many were removed
     */
    public function prune(): int
    {
        if (!\is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;

        foreach ($this->files() as $file) {
            $contents = @\file_get_contents($file);
            $entry = $contents === false ? null : $this->decode($contents);

            if (($entry === null || $entry->hasExpired()) && @\unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** How many entries are stored, expired ones included. */
    public function count(): int
    {
        return \count($this->files());
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function pathFor(string $key): string
    {
        return Path::join(
            $this->directory,
            $this->folderFor($key),
            \hash('xxh128', $key) . self::EXTENSION,
        );
    }

    /**
     * Which directory a key belongs in.
     *
     * The namespace is everything before the FIRST separator, because that is
     * how Cache builds a qualified key. Taking the last one instead would put a
     * colon inside a directory name the moment a key contained one, which is
     * legal in a key and not legal in a filename on Windows.
     */
    private function folderFor(string $key): string
    {
        $separator = \strpos($key, Cache::SEPARATOR);
        $namespace = $separator === false ? '' : \substr($key, 0, $separator);

        return $namespace === '' ? self::UNNAMESPACED : $namespace;
    }

    private function encode(string $key, mixed $value, ?int $expiresAt): string
    {
        try {
            return $this->sealer->seal(\serialize(['key' => $key, 'expires' => $expiresAt, 'value' => $value]));
        } catch (\Throwable) {
            // Cache refuses a closure outright; this catches one buried inside
            // an array or an object property, which is not worth walking every
            // value to find in advance.
            throw CacheException::notStorable($key, \get_debug_type($value));
        }
    }

    /** Null -- a miss -- for a file this application did not write; see Sealer. */
    private function decode(string $contents): ?CacheEntry
    {
        $bytes = $this->sealer->open($contents);

        if ($bytes === null) {
            return null;
        }

        /** @var mixed $data */
        $data = @\unserialize($bytes);

        if (!\is_array($data) || !\array_key_exists('value', $data) || !\array_key_exists('expires', $data)) {
            return null;
        }

        /** @var mixed $expires */
        $expires = $data['expires'];

        return new CacheEntry($data['value'], \is_int($expires) ? $expires : null);
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = [];

        if (!\is_dir($this->directory)) {
            return $found;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === \ltrim(self::EXTENSION, '.')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function removeUnder(string $directory, bool $removeItself): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
        }

        if ($removeItself) {
            @\rmdir($directory);
        }
    }
}
