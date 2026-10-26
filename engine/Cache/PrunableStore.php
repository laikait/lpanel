<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * A store that keeps expired entries until something sweeps them.
 *
 * Reading an expired entry removes it, so this is for the ones nobody asks for
 * again -- a key that included yesterday's date, a namespace a module stopped
 * using. `cache:clear --expired` calls it; a store whose backend expires
 * entries itself has nothing to sweep and does not implement it.
 */
interface PrunableStore extends CacheStore
{
    /** @return int how many expired entries were removed */
    public function prune(): int;
}
