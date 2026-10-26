<?php

declare(strict_types=1);

namespace App\Engine\Session;

use App\Engine\Error\FrameworkException;

/**
 * The session layer was asked to do something it cannot do correctly.
 *
 * Note what is not here: a session that could not be read, a store that is
 * unreachable, an id that has expired. None of those is an error -- they all
 * mean "start a new session", which is what a user experiences as being logged
 * out and what every one of them has seen before. Turning an unreadable session
 * file into a 500 would take a site down over one corrupt file.
 *
 * What is here is a mistake in how the application is put together, thrown
 * where whoever made it is still looking.
 */
final class SessionException extends FrameworkException
{
    public static function emptyKey(): self
    {
        return new self('A session key cannot be an empty string.');
    }

    public static function reservedKey(string $key): self
    {
        return new self(\sprintf(
            'The session key "%s" is reserved: keys beginning with "_" belong to the framework. '
            . 'One of them holds the id of the account this session is logged in as, so being '
            . 'able to write them would be a way to log in as somebody else.',
            $key,
        ));
    }

    /**
     * Thrown where the value was written, not where it failed to encode.
     *
     * The alternative -- discovering it at the end of the request, inside a
     * store, with no idea which key -- is how "the session sometimes does not
     * save" becomes a week of somebody's life.
     */
    public static function unserialisablePayload(string $key, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'The session value under "%s" cannot be stored: a session payload has to be '
                . 'JSON-serialisable. Keep an id in the session and load the object from it -- '
                . 'a session is not a cache, and an object put there goes stale the moment its row changes.',
                $key,
            ),
            previous: $previous,
        );
    }

    public static function unknownStore(string $name, string $known): self
    {
        return new self(\sprintf(
            'There is no session store called "%s". Configure session.store as one of: %s.',
            $name,
            $known,
        ));
    }

    public static function unwritableDirectory(string $path): self
    {
        return new self(\sprintf(
            'Sessions cannot be stored in %s: the directory does not exist and could not be created. '
            . 'Nobody would stay logged in, so this stops here rather than at the first request.',
            $path,
        ));
    }

    public static function noConnection(): self
    {
        return new self(
            'session.store is "database" but no database connection is configured. '
            . 'Set the database connection, or use the file store.',
        );
    }

    public static function missingTable(string $table, string $connection): self
    {
        return new self(\sprintf(
            'The session table "%s" does not exist on the "%s" connection. Create it with: '
            . 'php laika migrate --connection=%s, which adds it while session.store is "database".',
            $table,
            $connection,
            $connection,
        ));
    }
}
