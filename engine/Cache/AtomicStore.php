<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * A store that can take a key only when nobody holds it -- what a lock needs.
 *
 * Separate from CacheStore so that a store written for an application before
 * locks existed keeps working: Cache::lock() asks for this, and says so when
 * the configured store is not one. The file, database and array stores are;
 * the null store is not, because a lock that every caller gets is no lock.
 */
interface AtomicStore extends CacheStore
{
    /**
     * Store the value only if the key holds nothing unexpired, in one step no
     * other process can come between.
     *
     * @return bool true when this call stored it
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Remove the key only if it still holds exactly $value -- so a lock is
     * released by its holder, never by a process whose own lock expired and
     * was taken by someone else.
     */
    public function forgetIf(string $key, mixed $value): bool;
}
