<?php

declare(strict_types=1);

namespace App\Engine\Session\Stores;

use App\Engine\Session\SessionException;
use App\Engine\Session\SessionId;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\SessionStore;

/**
 * One JSON file per session, merged under an exclusive lock.
 *
 * The default, because it needs nothing installed and it is correct on one
 * machine -- which is where most applications start and where a good many of
 * them stay.
 *
 * **The lock is held across the read and the write, and nothing else.** That
 * single sentence is the difference between this and PHP's own session
 * handling, which takes the lock when the session opens and gives it back when
 * the script ends. Under PHP's rule, one endpoint that takes four seconds
 * blocks every other request from the same browser for four seconds, which is
 * the classic "why is my site slow only when logged in". Here the lock covers
 * a read-modify-write of one small file.
 *
 * **Filenames are hashes, not ids.** A session id is a live credential: anyone
 * who can see one can be that user. A directory listing, a backup manifest, an
 * `ls` in a support ticket -- none of those should hand over an account, so the
 * name on disk is a SHA-256 of the id and the id itself appears only inside the
 * file, which needs read permission to reach.
 *
 * **Scope is one machine**, the same limit the file cache and the schedule lock
 * have, and for the same reason: flock() across a network filesystem is a bet
 * on that filesystem's locking. Two web servers behind a load balancer need a
 * store both can see -- which is what the database store is for.
 */
final class FileStore implements SessionStore
{
    public const EXTENSION = '.sess';

    public function __construct(
        private readonly string $directory,
        private readonly int $permissions = 0o775,
    ) {}

    public function describe(): string
    {
        return 'file ' . $this->directory;
    }

    public function read(string $id): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        $file = $this->pathFor($id);

        if (!\is_file($file)) {
            return null;
        }

        $handle = @\fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            @\flock($handle, \LOCK_SH);

            return SessionRecord::decode($this->contents($handle), $id);
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    public function commit(string $id, \Closure $apply): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        $this->ensureDirectory();

        $file = $this->pathFor($id);
        $handle = @\fopen($file, 'c+b');

        if ($handle === false) {
            throw SessionException::unwritableDirectory($this->directory);
        }

        try {
            // Blocking rather than LOCK_NB. A writer that gave up on contention
            // would drop whatever the request had changed, and losing a login
            // because two tabs saved at once is precisely the failure this
            // interface exists to prevent.
            @\flock($handle, \LOCK_EX);

            $record = $apply(SessionRecord::decode($this->contents($handle), $id));

            if ($record === null) {
                @\ftruncate($handle, 0);
                @\flock($handle, \LOCK_UN);
                @\fclose($handle);
                @\unlink($file);

                return null;
            }

            $this->write($handle, $record);

            return $record;
        } finally {
            // Already closed on the delete path; closing a closed handle is
            // harmless here and the alternative is a flag to track it.
            if (\is_resource($handle)) {
                @\flock($handle, \LOCK_UN);
                @\fclose($handle);
            }
        }
    }

    public function destroy(string $id): bool
    {
        if (!SessionId::isValid($id)) {
            return false;
        }

        $file = $this->pathFor($id);

        return \is_file($file) && @\unlink($file);
    }

    public function gc(int $idle, int $absolute = 0): int
    {
        $removed = 0;

        foreach (\glob($this->directory . \DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [] as $file) {
            $contents = @\file_get_contents($file);
            // Swept by file name, which is the id's hash: the id that would
            // decode the record is exactly what is not available here.
            $record = $contents === false ? null : SessionRecord::forSweep($contents);

            // A file that does not decode is swept too. It is either a
            // half-written record from a machine that lost power or a leftover
            // from an older format, and in both cases nobody can log in with
            // it, so leaving it is just litter that grows.
            if ($record === null || $record->hasExpired($idle, $absolute)) {
                if (@\unlink($file)) {
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    private function contents(mixed $handle): string
    {
        if (!\is_resource($handle)) {
            return '';
        }

        \rewind($handle);
        $contents = \stream_get_contents($handle);

        return \is_string($contents) ? $contents : '';
    }

    private function write(mixed $handle, SessionRecord $record): void
    {
        if (!\is_resource($handle)) {
            return;
        }

        // Encoded before the file is truncated. A payload that cannot be
        // written must leave the previous one alone rather than replacing it
        // with nothing.
        $encoded = $record->encode();

        \rewind($handle);
        \ftruncate($handle, 0);
        \fwrite($handle, $encoded);
        \fflush($handle);
    }

    /** SHA-256, for the reason on the class. */
    private function pathFor(string $id): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . SessionRecord::keyFor($id) . self::EXTENSION;
    }

    private function ensureDirectory(): void
    {
        if (\is_dir($this->directory)) {
            return;
        }

        if (!@\mkdir($this->directory, $this->permissions, true) && !\is_dir($this->directory)) {
            throw SessionException::unwritableDirectory($this->directory);
        }
    }
}
