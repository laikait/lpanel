<?php

declare(strict_types=1);

namespace App\Engine\Scheduler\Locks;

use App\Engine\Scheduler\ScheduleLock;
use App\Engine\Scheduler\SchedulerException;

/**
 * One file per held lock, under system/Schedule.
 *
 * The whole design is one flag. Creating the file with "x" mode opens it
 * O_EXCL, which the operating system guarantees either creates the file or
 * fails -- on NTFS and on every POSIX filesystem -- so two processes racing for
 * the same lock produce one winner and one false. No lock file to leak, no
 * second lock protecting the first, nothing to unwind if a process disappears
 * between the two lines of a check-then-write.
 *
 * The same argument the file queue store makes with rename(). Taking it twice
 * is not repetition: both places need one atomic filesystem operation and
 * nothing else, and both are wrong the moment they need two.
 *
 * **Expiry is the other half.** A lock whose time has passed is not held, and
 * taking it over is deliberately not a delete-then-create -- that is two
 * operations with a race in the middle. It is a rename to a private name: the
 * loser's rename fails because the file is already gone, so exactly one process
 * gets to clear the way and then compete for the fresh create.
 *
 * **One machine.** Two servers sharing this over NFS would be trusting a
 * network filesystem's O_EXCL semantics, which is a well-known way to discover
 * that both of them ran the billing job. That is what the ScheduleLock
 * interface is for.
 */
final class FileLock implements ScheduleLock
{
    public const EXTENSION = '.lock';

    public function __construct(
        private readonly string $directory,
        private readonly int $permissions = 0o775,
    ) {}

    public function describe(): string
    {
        return 'file ' . $this->directory;
    }

    public function acquire(string $key, int $seconds): bool
    {
        $this->ensureDirectory();

        $file = $this->pathFor($key);

        if ($this->create($file, $key, $seconds)) {
            return true;
        }

        $record = $this->read($file);

        // Held by somebody who is still within their time.
        if ($record !== null && $record['expiresAt'] > \time()) {
            return false;
        }

        // Abandoned or unreadable. Whoever wins this rename gets to clear it;
        // the others fail here rather than racing each other to unlink.
        $claim = $file . '.' . \bin2hex(\random_bytes(6)) . '.stale';

        if (!@\rename($file, $claim)) {
            return false;
        }

        @\unlink($claim);

        return $this->create($file, $key, $seconds);
    }

    public function release(string $key): bool
    {
        $file = $this->pathFor($key);

        return \is_file($file) && @\unlink($file);
    }

    public function heldUntil(string $key): ?int
    {
        $record = $this->read($this->pathFor($key));

        if ($record === null || $record['expiresAt'] <= \time()) {
            return null;
        }

        return $record['expiresAt'];
    }

    public function held(): array
    {
        $keys = [];

        foreach ($this->files() as $file) {
            $record = $this->read($file);

            if ($record !== null && $record['expiresAt'] > \time()) {
                $keys[] = $record['key'];
            }
        }

        \sort($keys);

        return $keys;
    }

    /**
     * Remove every lock, held or not.
     *
     * Used by `schedule:unlock --all`. Deliberately not called by anything
     * automatic: a lock that looks stuck is usually a task still running, and
     * clearing it on a schedule's behalf is how the overlap this class exists
     * to prevent gets reintroduced.
     */
    public function flush(): int
    {
        $removed = 0;

        foreach ($this->files() as $file) {
            if (@\unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = \glob($this->directory . \DIRECTORY_SEPARATOR . '*' . self::EXTENSION);

        return $found === false ? [] : $found;
    }

    /**
     * The exclusive create that is the whole lock.
     *
     * The record is written after the handle exists, so a reader that arrives
     * between the two sees an empty file -- which read() reports as unreadable,
     * which acquire() treats as abandoned. That would be a race, except that
     * taking it over needs the rename above, and the process holding the open
     * handle is microseconds from finishing. The cost of losing it is one
     * skipped run of one task.
     */
    private function create(string $file, string $key, int $seconds): bool
    {
        $handle = @\fopen($file, 'xb');

        if ($handle === false) {
            return false;
        }

        $payload = \json_encode([
            'key' => $key,
            'acquiredAt' => \time(),
            'expiresAt' => \time() + \max(1, $seconds),
            'pid' => \getmypid(),
            'host' => \gethostname(),
        ], \JSON_PRETTY_PRINT);

        @\fwrite($handle, $payload === false ? '{}' : $payload);
        @\fclose($handle);

        return true;
    }

    /** @return array{key: string, expiresAt: int}|null */
    private function read(string $file): ?array
    {
        if (!\is_file($file)) {
            return null;
        }

        $contents = @\file_get_contents($file);

        if ($contents === false || $contents === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = \json_decode($contents, true);

        if (!\is_array($decoded) || !\is_string($decoded['key'] ?? null) || !\is_int($decoded['expiresAt'] ?? null)) {
            return null;
        }

        return ['key' => $decoded['key'], 'expiresAt' => $decoded['expiresAt']];
    }

    /**
     * A readable name plus a hash.
     *
     * The hash is what makes it correct -- keys may hold characters a filename
     * may not, and "Billing" and "billing" are one file on Windows. The
     * readable half is what makes a directory listing useful at the moment
     * somebody is looking at it wondering why a task is being skipped.
     */
    private function pathFor(string $key): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]+/', '-', $key) ?? 'lock';

        return $this->directory . \DIRECTORY_SEPARATOR
            . \substr($safe, 0, 48) . '-' . \substr(\hash('xxh128', $key), 0, 12) . self::EXTENSION;
    }

    private function ensureDirectory(): void
    {
        if (\is_dir($this->directory)) {
            return;
        }

        if (!@\mkdir($this->directory, $this->permissions, true) && !\is_dir($this->directory)) {
            throw SchedulerException::unwritable($this->directory);
        }
    }
}
