<?php

declare(strict_types=1);

namespace App\Engine\Queue\Stores;

use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueException;
use App\Engine\Queue\QueueStore;
use App\Engine\Support\Path;

/**
 * A durable queue made of files and one system call.
 *
 * The whole design rests on rename(). Moving a file from pending/ to reserved/
 * either succeeds or fails as a single operation, on NTFS and on every POSIX
 * filesystem, and the loser of a race gets false rather than a second copy of
 * the job. That is the entire concurrency story: no lock files, no advisory
 * locking, nothing to leak when a worker is killed.
 *
 *     system/Queue/
 *       <queue>/pending/<due>-<id>.job     waiting; the name sorts by due time
 *       <queue>/reserved/<id>.job          claimed, with its reservation inside
 *       failed/<id>.job                    gave up; kept until somebody looks
 *
 * The due time is in the filename because a directory listing is then already
 * in the order a worker wants, and choosing the next job costs no reads at all.
 * Ten thousand pending jobs is ten thousand directory entries, which is fine;
 * ten million is the point at which this should be a database, and the store
 * interface is the seam for that.
 *
 * Moving between states is always write-then-rename, never rewrite-in-place: a
 * worker that dies halfway through releasing a job leaves the job reserved --
 * and a reservation expires, so it comes back. Losing work is not on the list
 * of things that can happen here; running something twice after a crash is, and
 * that is the trade every at-least-once queue makes.
 *
 * What this is not is a cluster queue. Two machines sharing this directory over
 * NFS or SMB are two machines trusting a network filesystem's rename
 * semantics, which is a bet worth not making. One machine, any number of
 * workers: correct.
 */
final class FileStore implements QueueStore
{
    public const EXTENSION = '.job';
    public const PENDING = 'pending';
    public const RESERVED = 'reserved';
    public const FAILED = 'failed';

    public function __construct(
        private readonly string $directory,
        private readonly int $permissions = 0o775,
    ) {}

    public function describe(): string
    {
        return 'file ' . $this->directory;
    }

    public function push(QueuedJob $job): bool
    {
        $directory = $this->path($job->queue, self::PENDING);

        if (!$this->ensure($directory)) {
            return false;
        }

        return $this->write(
            Path::join($directory, \sprintf('%010d-%s%s', $job->availableAt, $job->id, self::EXTENSION)),
            $job,
        );
    }

    public function reserve(string $queue, int $seconds): ?QueuedJob
    {
        // Lapsed reservations first: a worker that was killed left work behind,
        // and it is due before anything queued since.
        $this->recoverExpired($queue);

        $pending = $this->path($queue, self::PENDING);
        $reserved = $this->path($queue, self::RESERVED);

        if (!\is_dir($pending) || !$this->ensure($reserved)) {
            return null;
        }

        $now = \time();

        foreach ($this->entries($pending) as $file) {
            // The name begins with the due time, so this is a string compare
            // rather than a read. Everything after the first one that is not
            // due yet is also not due yet.
            if ($this->dueTimeOf($file) > $now) {
                break;
            }

            $job = $this->read(Path::join($pending, $file));

            if ($job === null) {
                continue;
            }

            $claim = Path::join($reserved, $job->id . self::EXTENSION);

            // The race, and its resolution. Whoever's rename() returns true has
            // the job; everybody else moves on to the next file.
            if (!@\rename(Path::join($pending, $file), $claim)) {
                continue;
            }

            $held = $job->reservedFor($seconds, $now);

            if (!$this->write($claim, $held)) {
                return null;
            }

            return $held;
        }

        return null;
    }

    public function acknowledge(QueuedJob $job): bool
    {
        $file = Path::join($this->path($job->queue, self::RESERVED), $job->id . self::EXTENSION);

        return !\is_file($file) || @\unlink($file);
    }

    public function release(QueuedJob $job): bool
    {
        $reserved = Path::join($this->path($job->queue, self::RESERVED), $job->id . self::EXTENSION);
        $pending = $this->path($job->queue, self::PENDING);

        if (!\is_file($reserved)) {
            // Its reservation lapsed and something else recovered it; the
            // recovered copy is the live one and this call has nothing to do.
            return false;
        }

        if (!$this->ensure($pending) || !$this->write($reserved, $job)) {
            return false;
        }

        // Rewritten, then moved in one operation.
        return @\rename(
            $reserved,
            Path::join($pending, \sprintf('%010d-%s%s', $job->availableAt, $job->id, self::EXTENSION)),
        );
    }

    public function fail(QueuedJob $job): bool
    {
        $reserved = Path::join($this->path($job->queue, self::RESERVED), $job->id . self::EXTENSION);
        $failed = Path::join($this->directory, self::FAILED);

        if (!$this->ensure($failed)) {
            return false;
        }

        if (!\is_file($reserved)) {
            return $this->write(Path::join($failed, $job->id . self::EXTENSION), $job);
        }

        return $this->write($reserved, $job)
            && @\rename($reserved, Path::join($failed, $job->id . self::EXTENSION));
    }

    public function failed(): array
    {
        $directory = Path::join($this->directory, self::FAILED);
        $jobs = [];

        foreach ($this->entries($directory) as $file) {
            $job = $this->read(Path::join($directory, $file));

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function forget(string $id): bool
    {
        $file = Path::join($this->directory, self::FAILED, $id . self::EXTENSION);

        return !\is_file($file) || @\unlink($file);
    }

    public function size(string $queue): int
    {
        return \count($this->entries($this->path($queue, self::PENDING)))
            + \count($this->entries($this->path($queue, self::RESERVED)));
    }

    public function queues(): array
    {
        if (!\is_dir($this->directory)) {
            return [];
        }

        $names = [];

        foreach (\scandir($this->directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::FAILED) {
                continue;
            }

            if (\is_dir(Path::join($this->directory, $entry)) && $this->size($entry) > 0) {
                $names[] = $entry;
            }
        }

        \sort($names);

        return $names;
    }

    public function clear(?string $queue = null): bool
    {
        foreach ($queue === null ? $this->allQueueDirectories() : [$queue] as $name) {
            foreach ([self::PENDING, self::RESERVED] as $state) {
                $directory = $this->path($name, $state);

                foreach ($this->entries($directory) as $file) {
                    @\unlink(Path::join($directory, $file));
                }
            }
        }

        return true;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Put lapsed reservations back on the queue.
     *
     * This is what makes a killed worker harmless. The job it was holding is
     * moved back to pending with its attempt count intact, so a job that kills
     * whatever picks it up still exhausts its tries rather than cycling
     * forever.
     */
    private function recoverExpired(string $queue): void
    {
        $reserved = $this->path($queue, self::RESERVED);
        $pending = $this->path($queue, self::PENDING);
        $now = \time();

        foreach ($this->entries($reserved) as $file) {
            $path = Path::join($reserved, $file);
            $job = $this->read($path);

            if ($job === null || $job->isReserved($now)) {
                continue;
            }

            if (!$this->ensure($pending)) {
                return;
            }

            $recovered = $job->releasedAfter(0, $now);

            if ($this->write($path, $recovered)) {
                @\rename(
                    $path,
                    Path::join($pending, \sprintf('%010d-%s%s', $recovered->availableAt, $job->id, self::EXTENSION)),
                );
            }
        }
    }

    /** @return list<string> filenames, sorted, which for pending is due order */
    private function entries(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (\scandir($directory) ?: [] as $entry) {
            if (\str_ends_with($entry, self::EXTENSION)) {
                $files[] = $entry;
            }
        }

        \sort($files);

        return $files;
    }

    /** @return list<string> */
    private function allQueueDirectories(): array
    {
        if (!\is_dir($this->directory)) {
            return [];
        }

        $names = [];

        foreach (\scandir($this->directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== self::FAILED
                && \is_dir(Path::join($this->directory, $entry))) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    private function dueTimeOf(string $filename): int
    {
        return (int) \substr($filename, 0, 10);
    }

    private function path(string $queue, string $state): string
    {
        return Path::join($this->directory, $queue, $state);
    }

    private function ensure(string $directory): bool
    {
        if (\is_dir($directory)) {
            return true;
        }

        if (!@\mkdir($directory, $this->permissions, true) && !\is_dir($directory)) {
            throw QueueException::unwritable($directory);
        }

        return true;
    }

    private function write(string $file, QueuedJob $job): bool
    {
        $data = $job->toArray();

        // The payload is PHP's serialize() output, which is bytes rather than
        // text and may not be valid UTF-8. Base64 here keeps the file JSON --
        // readable, greppable, and inspectable when something has gone wrong --
        // without making json_encode() the thing that fails on a job carrying a
        // binary field.
        $data['payload'] = \base64_encode($job->payload);

        $contents = \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

        // Written to a temporary name and renamed, so a reader never sees half
        // a job even when it is looking at the same instant.
        $temporary = $file . '.' . \getmypid() . '.tmp';

        if (@\file_put_contents($temporary, $contents, \LOCK_EX) === false) {
            return false;
        }

        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    private function read(string $file): ?QueuedJob
    {
        $contents = @\file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        /** @var mixed $data */
        $data = \json_decode($contents, true);

        if (!\is_array($data) || !isset($data['payload']) || !\is_string($data['payload'])) {
            return null;
        }

        $payload = \base64_decode($data['payload'], true);

        if ($payload === false) {
            return null;
        }

        $data['payload'] = $payload;

        return QueuedJob::fromArray($data);
    }
}
