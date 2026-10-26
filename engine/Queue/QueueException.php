<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Error\FrameworkException;

/**
 * The queue refused something, or could not read something back.
 *
 * Everything here happens at one of two moments: dispatching a job that cannot
 * work, which is caught in development the first time the line runs, or reading
 * a payload that no longer describes a class, which is what a deployment that
 * renamed a job looks like from a worker's point of view.
 *
 * A job that throws while running is not one of these. That is the
 * application's exception, and the worker's job is to retry it and eventually
 * record it, not to convert it into something else.
 */
final class QueueException extends FrameworkException
{
    public static function notHandleable(string $class): self
    {
        return new self(\sprintf(
            'The job %s has no handle() method. A job carries its data in the constructor and does '
            . 'its work in handle(), whose arguments are injected.',
            $class,
        ));
    }

    public static function notStorable(string $class, ?\Throwable $previous): self
    {
        return new self(\sprintf(
            'The job %s cannot be serialised, so it cannot be queued. Its constructor should take '
            . 'the data the work needs -- an id rather than the object it names -- and handle() '
            . 'should take the collaborators.',
            $class,
        ), 0, $previous);
    }

    public static function unreadablePayload(string $class, ?\Throwable $previous): self
    {
        return new self(\sprintf(
            'A queued %s could not be read back. The class may have been renamed or removed since '
            . 'the job was dispatched.',
            $class,
        ), 0, $previous);
    }

    public static function unsealedPayload(string $class): self
    {
        return new self(\sprintf(
            'A queued %s was not written by this application with its current APP_KEY, so it was not '
            . 'unserialized. It was queued before the key was set or changed -- or somebody else wrote it.',
            $class,
        ));
    }

    public static function unusableQueueName(string $name): self
    {
        return new self(\sprintf(
            'Queue name "%s" is invalid. A queue is a short lowercase name, such as "default" or "billing".',
            $name,
        ));
    }

    public static function missingTable(string $table, string $connection): self
    {
        return new self(\sprintf(
            'The queue table "%s" does not exist on the "%s" connection. Create it with: '
            . 'php laika migrate --connection=%s, which adds it while queue.store is "database".',
            $table,
            $connection,
            $connection,
        ));
    }

    public static function unwritable(string $directory): self
    {
        return new self(\sprintf(
            'The queue directory %s does not exist and could not be created.',
            $directory,
        ));
    }
}
