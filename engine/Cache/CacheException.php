<?php

declare(strict_types=1);

namespace App\Engine\Cache;

use App\Engine\Error\FrameworkException;

/**
 * The cache refused something.
 *
 * Note how few of these there are. A cache that cannot reach its backend does
 * not throw -- it misses, and the application is slower rather than broken,
 * which is the whole point of a cache being optional. What is left are
 * programming mistakes: a key that is not a key, a namespace that is not a
 * namespace, a value that cannot be stored at all.
 */
final class CacheException extends FrameworkException
{
    public static function unusableKey(string $key, string $why): self
    {
        return new self(\sprintf(
            'Cache key "%s" is unusable: %s. A key is up to %d characters of letters, digits, '
            . 'dot, dash, colon and underscore.',
            $key,
            $why,
            Cache::MAX_KEY,
        ));
    }

    public static function unusableNamespace(string $namespace): self
    {
        return new self(\sprintf(
            'Cache namespace "%s" is invalid. A namespace is a short lowercase name, such as '
            . '"assets" or "billing".',
            $namespace,
        ));
    }

    public static function notStorable(string $key, string $type): self
    {
        return new self(\sprintf(
            'The value for cache key "%s" is a %s, which cannot be stored. A cached value has to '
            . 'survive leaving this process, and a closure or a connection cannot.',
            $key,
            $type,
        ));
    }

    public static function cannotLock(string $store): self
    {
        return new self(\sprintf(
            'The cache store (%s) cannot hold a lock: a lock needs a store that can take a key only when it is free. '
            . 'Use the file or database store; the null store gives every caller the lock, which is no lock.',
            $store,
        ));
    }

    public static function lockTimeout(string $key, float $seconds): self
    {
        return new self(\sprintf('The lock %s was not free within %s seconds.', $key, (string) $seconds));
    }
}
