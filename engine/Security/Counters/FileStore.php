<?php

declare(strict_types=1);

namespace App\Engine\Security\Counters;

use App\Engine\Security\Counter;
use App\Engine\Security\CounterStore;
use App\Engine\Security\SecurityException;

/**
 * One file per counter, under system/Security, incremented under a lock.
 *
 * The atomicity that CounterStore insists on comes from flock(). Open the file,
 * take an exclusive lock, read, add one, write, release -- and a second process
 * arriving in the middle waits rather than reading a stale count. This is the
 * one place in the framework that holds a lock across a read and a write, and
 * it is worth being explicit about why: everywhere else, a single atomic
 * syscall was enough. Here the new value depends on the old one, and no single
 * filesystem operation does that.
 *
 * The scope is one machine, which is the same limit the file queue store and
 * the schedule lock have. flock() over NFS is a bet on the network filesystem's
 * locking, and for a rate limiter the cost of losing that bet is that every
 * machine allows the full quota independently.
 *
 * Expired files are not swept. A counter deletes itself the next time its key
 * is touched, which is the same bargain the file cache makes, and the
 * directory's size is bounded by the number of distinct keys rather than by
 * traffic -- ten thousand IP addresses is ten thousand small files, and that is
 * the point at which this should be Redis.
 */
final class FileStore implements CounterStore
{
    public const EXTENSION = '.count';

    public function __construct(
        private readonly string $directory,
        private readonly int $permissions = 0o775,
    ) {}

    public function describe(): string
    {
        return 'file ' . $this->directory;
    }

    public function hit(string $key, int $window): Counter
    {
        $this->ensureDirectory();

        $handle = @\fopen($this->pathFor($key), 'c+b');

        if ($handle === false) {
            throw SecurityException::unwritableDestination($this->directory);
        }

        try {
            // Blocking, not LOCK_NB. A limiter that gave up on contention would
            // stop limiting exactly when it is needed, which is the wrong way
            // round: waiting microseconds is cheaper than letting a burst past.
            @\flock($handle, \LOCK_EX);

            $counter = $this->read($handle);
            $now = \time();

            $counter = $counter === null || $counter->hasExpired($now)
                ? new Counter(1, $now + \max(1, $window))
                : new Counter($counter->count + 1, $counter->expiresAt);

            $this->write($handle, $counter);

            return $counter;
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    public function peek(string $key): ?Counter
    {
        $file = $this->pathFor($key);

        if (!\is_file($file)) {
            return null;
        }

        $handle = @\fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            @\flock($handle, \LOCK_SH);

            $counter = $this->read($handle);
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }

        if ($counter === null || $counter->hasExpired()) {
            // An expired counter removes itself on the way past, so a key that
            // is still being asked about never needs a sweep.
            @\unlink($file);

            return null;
        }

        return $counter;
    }

    public function clear(string $key): bool
    {
        $file = $this->pathFor($key);

        return \is_file($file) && @\unlink($file);
    }

    public function flush(): bool
    {
        foreach (\glob($this->directory . \DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [] as $file) {
            @\unlink($file);
        }

        return true;
    }

    private function read(mixed $handle): ?Counter
    {
        if (!\is_resource($handle)) {
            return null;
        }

        \rewind($handle);
        $contents = \stream_get_contents($handle);

        if (!\is_string($contents) || $contents === '') {
            return null;
        }

        // "<count> <expiresAt>": two integers, because a counter that needed
        // JSON would be a counter doing more than counting.
        $parts = \explode(' ', \trim($contents));

        if (\count($parts) !== 2 || !\ctype_digit($parts[0]) || !\ctype_digit($parts[1])) {
            return null;
        }

        return new Counter((int) $parts[0], (int) $parts[1]);
    }

    private function write(mixed $handle, Counter $counter): void
    {
        if (!\is_resource($handle)) {
            return;
        }

        \rewind($handle);
        \ftruncate($handle, 0);
        \fwrite($handle, $counter->count . ' ' . $counter->expiresAt);
        \fflush($handle);
    }

    /**
     * A hash, and nothing readable.
     *
     * The opposite of the schedule lock, whose filenames are half readable so
     * an operator can see what is held. A rate-limit key is an IP address, a
     * username or an API token; a directory listing of those is a log of who
     * has been using the site, sitting in a file nobody thinks of as a log.
     */
    private function pathFor(string $key): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . \hash('xxh128', $key) . self::EXTENSION;
    }

    private function ensureDirectory(): void
    {
        if (\is_dir($this->directory)) {
            return;
        }

        if (!@\mkdir($this->directory, $this->permissions, true) && !\is_dir($this->directory)) {
            throw SecurityException::unwritableDestination($this->directory);
        }
    }
}
