<?php

declare(strict_types=1);

namespace App\Engine\Session;

/**
 * Somewhere a session can live between requests.
 *
 * Five methods, and one of them is the whole design.
 *
 * **commit() takes a closure, not a record.** Every other store interface in
 * this framework takes a value and writes it; this one hands the store a
 * function and asks it to apply that function to whatever is currently there,
 * under whatever exclusion the backend can offer. The reason is concurrency,
 * and it is the reason PHP's own sessions behave the way they do.
 *
 * A browser makes several requests at once -- a page and three fetches -- and
 * all of them carry the same session. PHP solves this by locking the session
 * for the whole request, which is correct and is also why one slow endpoint
 * blocks every other request from that user. Nobody wants that, so the usual
 * alternative is to read at the start, write the whole array at the end, and
 * quietly lose whichever request finished first.
 *
 * Here the request writes what it CHANGED rather than what it read. Two
 * concurrent requests, one setting 'cart' and one setting 'locale', both
 * survive -- because the merge happens inside the store, at write time, while
 * the store holds its lock. Two requests writing the SAME key still resolve to
 * the last writer, which is inherent: there is no answer to "both of us set it"
 * that a session layer can pick for you.
 *
 * The lock is held across a read-modify-write of one record and nothing else,
 * so it is measured in microseconds rather than in the length of a request.
 *
 * **Failures are quiet, refusals are loud.** A store that cannot be reached
 * answers null from read() and false from commit(), because a user who has to
 * log in again is better than a site that is down. A store asked to do
 * something impossible -- an unwritable directory, a missing table -- throws,
 * because that is a deployment mistake and hiding it makes it permanent.
 */
interface SessionStore
{
    /** One line for about and session:gc, e.g. "file system/Sessions". */
    public function describe(): string;

    /**
     * The record under this id, or null when there is none.
     *
     * The store does not judge expiry: that is policy, it depends on
     * configuration the store has not been given, and a store that expired
     * records on its own would make gc() and read() disagree.
     */
    public function read(string $id): ?SessionRecord;

    /**
     * Apply a change to one record, atomically with respect to other writers.
     *
     * $apply receives the record as it is now -- or null when there is none --
     * and returns the record to store. Returning null from $apply deletes it.
     *
     * @param \Closure(?SessionRecord): ?SessionRecord $apply
     *
     * @return SessionRecord|null what is now stored, or null if it was removed
     *                            or could not be written
     */
    public function commit(string $id, \Closure $apply): ?SessionRecord;

    public function destroy(string $id): bool;

    /**
     * Remove everything past its lifetime, and say how many.
     *
     * Both clocks, because a store that swept only on idle time would leave
     * every session that something keeps warm. Returns a count so that
     * session:gc can print it -- a sweep that reports nothing is a sweep nobody
     * notices has stopped working.
     */
    public function gc(int $idle, int $absolute = 0): int;
}
