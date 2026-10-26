<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

use App\Engine\Error\FrameworkException;
use App\Engine\Queue\Job;

/**
 * The scheduler refused a declaration.
 *
 * Every one of these is thrown while a module.php is being replayed, which is
 * the only honest moment for them. A schedule is a thing that runs at three in
 * the morning with nobody watching, so a mistake in one has to surface when the
 * application starts rather than the first time the clock reaches it -- a
 * misspelt command name that fails silently for a month is the failure mode
 * this whole class exists to prevent.
 *
 * A scheduled task that throws while running is not one of these. That is the
 * application's exception; the scheduler records it and moves on to the next
 * task, exactly as the worker does with a job.
 */
final class SchedulerException extends FrameworkException
{
    public static function unusableIdentifier(string $id): self
    {
        return new self(\sprintf(
            'Schedule id "%s" is invalid. An id is a short lowercase name such as "invoice:send-reminders"; '
            . 'it becomes a lock key, so it is held to the same rule as a queue name.',
            $id,
        ));
    }

    public static function duplicateIdentifier(string $id, ?string $first, ?string $second): self
    {
        return new self(\sprintf(
            'Two schedules are both called "%s" (%s and %s). The id is the lock key, so two of them '
            . 'would take each other\'s lock. Give one of them its own with ->identify().',
            $id,
            $first ?? 'unknown',
            $second ?? 'unknown',
        ));
    }

    public static function unparsableExpression(string $expression, string $reason): self
    {
        return new self(\sprintf(
            'The cron expression "%s" cannot be read: %s. Five fields are expected -- minute, hour, '
            . 'day of month, month, day of week -- or one of @hourly, @daily, @weekly, @monthly, @yearly.',
            $expression,
            $reason,
        ));
    }

    public static function unknownCommand(string $id, string $command): self
    {
        return new self(\sprintf(
            'The schedule "%s" runs the command "%s", which is not registered. A scheduled command '
            . 'is checked when the application starts, not at three in the morning.',
            $id,
            $command,
        ));
    }

    public static function notAJob(string $id, string $class): self
    {
        return new self(\sprintf(
            'The schedule "%s" points at %s, which is not a %s.',
            $id,
            $class,
            Job::class,
        ));
    }

    public static function jobNeedsArguments(string $id, string $class): self
    {
        return new self(\sprintf(
            'The schedule "%s" cannot build %s, because its constructor requires arguments. A scheduled '
            . 'job is dispatched with nothing to say to it, so it has to be constructible on its own; '
            . 'a job that needs data belongs on a queue, pushed by whatever knows the data.',
            $id,
            $class,
        ));
    }

    public static function queueingWhatIsNotAJob(string $id): self
    {
        return new self(\sprintf(
            'The schedule "%s" asked for a queue, but only a scheduled job goes through one. A command '
            . 'and a callback run in the scheduler\'s own process; queueing either would mean '
            . 'serialising a console invocation, which is a worse thing to keep on disk than a job.',
            $id,
        ));
    }

    public static function unusableTime(string $id, string $time): self
    {
        return new self(\sprintf(
            'The schedule "%s" was given the time "%s". A time of day is written as HH:MM, e.g. "02:30".',
            $id,
            $time,
        ));
    }

    public static function unknownTimezone(string $id, string $timezone): self
    {
        return new self(\sprintf(
            'The schedule "%s" names the timezone "%s", which PHP does not know. Use an identifier from '
            . 'timezone_identifiers_list(), such as "Europe/Berlin".',
            $id,
            $timezone,
        ));
    }

    public static function unwritable(string $directory): self
    {
        return new self(\sprintf(
            'The schedule lock directory %s does not exist and could not be created. Without it the '
            . 'scheduler cannot stop two runs of the same task overlapping.',
            $directory,
        ));
    }
}
