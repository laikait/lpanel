<?php

declare(strict_types=1);

namespace App\Engine\Session\Stores;

use App\Engine\Session\SessionId;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\SessionStore;

/**
 * Sessions in this process's memory, for tests and for the console.
 *
 * **Never right for a web application**, and session:check says so: a request
 * is a process that ends, so a session kept in its memory has been forgotten
 * before the response reaches the browser. It is here because a test that
 * exercises logging in should not leave files on the machine running it, and
 * because a console command that touches a session needs somewhere to put one.
 *
 * **It encodes and decodes exactly like the file store.** That is the part
 * worth noticing. A memory store that simply held the array would accept a PDO
 * connection, a closure or a model object without complaint -- and the
 * application would pass its tests and then fail in production against a store
 * that has to write the value down. Round-tripping through the same JSON makes
 * "it worked in tests" mean something.
 */
final class ArrayStore implements SessionStore
{
    /** @var array<string, string> id => encoded record */
    private array $records = [];

    public function describe(): string
    {
        return 'memory (this process only)';
    }

    public function read(string $id): ?SessionRecord
    {
        if (!SessionId::isValid($id) || !isset($this->records[$id])) {
            return null;
        }

        return SessionRecord::decode($this->records[$id], $id);
    }

    public function commit(string $id, \Closure $apply): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        $record = $apply($this->read($id));

        if ($record === null) {
            unset($this->records[$id]);

            return null;
        }

        // Encoded now, so that an unserialisable value is refused here rather
        // than on whichever machine first runs a real store.
        $this->records[$id] = $record->encode();

        return $record;
    }

    public function destroy(string $id): bool
    {
        if (!isset($this->records[$id])) {
            return false;
        }

        unset($this->records[$id]);

        return true;
    }

    public function gc(int $idle, int $absolute = 0): int
    {
        $removed = 0;

        foreach ($this->records as $id => $encoded) {
            $record = SessionRecord::decode($encoded, (string) $id);

            if ($record === null || $record->hasExpired($idle, $absolute)) {
                unset($this->records[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    /** How many sessions are held, for tests that assert on exactly that. */
    public function count(): int
    {
        return \count($this->records);
    }
}
