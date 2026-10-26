<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Input;
use App\Engine\Cli\Output;
use App\Engine\Container\Container;
use App\Engine\Hook\HookEngine;
use App\Engine\Queue\Job;
use App\Engine\Queue\Queue;

/**
 * Decides what is due and runs it.
 *
 * There is **one cron line**, and this is what it calls:
 *
 *     * * * * *  cd /var/www/app && php laika schedule:run >> /dev/null 2>&1
 *
 * Every schedule in the application lives in the module that owns it, and the
 * crontab holds one entry that never changes. That is the entire trade the
 * scheduler makes: a machine's crontab is edited by whoever has shell access
 * and is not in version control, so anything expressed there is invisible to
 * the people who wrote the code and absent from the deployment that installs
 * it. Moving it into module.php makes a schedule reviewable, testable and
 * deleted along with the module that needed it.
 *
 * **It does not hold a process open.** Nothing here loops or sleeps. The
 * scheduler is cron's shape, not a daemon's: started once a minute, it decides,
 * it runs what is due, it exits. A resident scheduler has to be supervised,
 * restarted on deployment, and watched for having silently died -- and it buys
 * nothing an application scheduled in minutes can use.
 *
 * **A failing task does not stop the run.** Each is run inside its own try, so
 * a broken cleanup job does not take the billing run with it. The exit code of
 * `schedule:run` reports that something failed, which is what a monitoring
 * system reads.
 *
 * **Commands are dispatched in this process, not shelled out.** exec()ing a new
 * PHP would need the binary located, the command line quoted for two operating
 * systems, and would lose the container, the configuration and the log this
 * process has already built. The cost is that one task can exhaust the memory
 * or time of the run it shares -- which is why a long task is a job, put on the
 * queue and run by a worker instead.
 */
final class Scheduler
{
    public const DEFAULT_LOCK_SECONDS = 3600;

    public function __construct(
        private readonly ScheduleRegistry $schedules,
        private readonly ScheduleLock $lock,
        private readonly Container $container,
        private readonly CommandRegistry $commands,
        private readonly CommandDispatcher $dispatcher,
        private readonly Queue $queue,
        private readonly HookEngine $hooks,
        private readonly string $timezone = 'UTC',
        private readonly int $lockSeconds = self::DEFAULT_LOCK_SECONDS,
    ) {}

    public function schedules(): ScheduleRegistry
    {
        return $this->schedules;
    }

    public function lock(): ScheduleLock
    {
        return $this->lock;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** @return list<Schedule> */
    public function due(?\DateTimeImmutable $at = null): array
    {
        return $this->schedules->due($at ?? $this->now(), $this->timezone);
    }

    /**
     * Run everything due at this minute.
     *
     * @return list<ScheduleResult>
     */
    public function run(?\DateTimeImmutable $at = null): array
    {
        $results = [];

        foreach ($this->due($at) as $schedule) {
            $results[] = $this->runOne($schedule);
        }

        return $results;
    }

    /**
     * Run one task, whatever the clock says.
     *
     * The lock is still taken. Forcing a task past its schedule is an operator
     * deciding it should run now; it is not a decision that it may run beside a
     * copy of itself, and those two things are separate on purpose.
     */
    public function runOne(Schedule $schedule): ScheduleResult
    {
        $condition = $schedule->condition();

        // Anything but true is a no, including a callback that forgot to
        // return. A condition exists to answer one question, and reading a
        // missing answer as "yes, run the billing" is the wrong way to be
        // lenient.
        if ($condition !== null && $this->container->call($condition) !== true) {
            return $this->announce(new ScheduleResult(
                $schedule,
                ScheduleOutcome::Skipped,
                'A condition on this schedule did not return true.',
            ));
        }

        $locking = $schedule->guardsAgainstOverlap($this->lockSeconds);

        if ($locking && !$this->lock->acquire($schedule->id(), $schedule->locksFor($this->lockSeconds))) {
            return $this->announce(new ScheduleResult(
                $schedule,
                ScheduleOutcome::Skipped,
                $this->stillRunning($schedule),
            ));
        }

        $this->hooks->do('schedule.started', $schedule);

        $started = \microtime(true);

        try {
            [$outcome, $message, $output] = $this->execute($schedule);

            $result = new ScheduleResult(
                $schedule,
                $outcome,
                $message,
                $output,
                \microtime(true) - $started,
            );
        } catch (\Throwable $e) {
            $result = new ScheduleResult(
                $schedule,
                ScheduleOutcome::Failed,
                $e->getMessage(),
                '',
                \microtime(true) - $started,
                $e,
            );

            $this->hooks->do('schedule.failed', $e, $schedule);
        } finally {
            if ($locking) {
                $this->lock->release($schedule->id());
            }
        }

        return $this->announce($result);
    }

    /**
     * @return array{ScheduleOutcome, string, string} outcome, message, captured output
     */
    private function execute(Schedule $schedule): array
    {
        return match ($schedule->target) {
            ScheduleTarget::Command => $this->runCommand($schedule),
            ScheduleTarget::Job => $this->runJob($schedule),
            ScheduleTarget::Callback => $this->runCallback($schedule),
        };
    }

    /**
     * @return array{ScheduleOutcome, string, string}
     */
    private function runCommand(Schedule $schedule): array
    {
        $name = \is_string($schedule->handler) ? $schedule->handler : '';
        $command = $this->commands->get($name);

        if ($command === null) {
            throw SchedulerException::unknownCommand($schedule->id(), $name);
        }

        // A stream of its own, so that whatever the command prints belongs to
        // this result rather than to the console that started the run. Both
        // streams are the same one: a scheduled command's error output is part
        // of the story of what it did, and splitting it would mean deciding
        // which half to keep.
        $stream = \fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw SchedulerException::unwritable('php://temp');
        }

        try {
            $status = $this->dispatcher->dispatch(
                $command,
                Input::parse($command, $schedule->arguments),
                new Output($stream, $stream),
            );

            \rewind($stream);
            $printed = (string) \stream_get_contents($stream);
        } finally {
            \fclose($stream);
        }

        // A command reports failure in its exit code, which is the console's
        // contract with a shell. Honouring it here is what makes a scheduled
        // command behave the way the same command in a crontab would.
        return $status === 0
            ? [ScheduleOutcome::Ran, '', $printed]
            : [ScheduleOutcome::Failed, \sprintf('%s exited %d.', $name, $status), $printed];
    }

    /**
     * @return array{ScheduleOutcome, string, string}
     */
    private function runJob(Schedule $schedule): array
    {
        $class = \is_string($schedule->handler) ? $schedule->handler : '';

        /** @var mixed $job */
        $job = $this->container->make($class);

        if (!$job instanceof Job) {
            throw SchedulerException::notAJob($schedule->id(), $class);
        }

        $id = $this->queue->push($job, $schedule->queue());

        return [
            ScheduleOutcome::Queued,
            \sprintf('Queued as %s on "%s".', $id, $this->queue->name($schedule->queue())),
            '',
        ];
    }

    /**
     * @return array{ScheduleOutcome, string, string}
     */
    private function runCallback(Schedule $schedule): array
    {
        if (!$schedule->handler instanceof \Closure) {
            throw SchedulerException::unknownCommand($schedule->id(), 'a callback');
        }

        $this->container->call($schedule->handler);

        return [ScheduleOutcome::Ran, '', ''];
    }

    /**
     * Say what happened, once, for every outcome.
     *
     * One hook rather than one per case. Something that only cares about
     * failures reads the outcome, and something that wants a record of the
     * nightly run -- which is most of what listens -- does not have to register
     * four listeners to get a complete picture. schedule.failed exists as well
     * because an exception is a different kind of thing from a result, and a
     * listener that only wants those should not have to unwrap one.
     */
    private function announce(ScheduleResult $result): ScheduleResult
    {
        $this->hooks->do('schedule.finished', $result);

        return $result;
    }

    private function stillRunning(Schedule $schedule): string
    {
        $until = $this->lock->heldUntil($schedule->id());

        return $until === null
            ? 'Another run holds the lock.'
            : \sprintf(
                'Another run holds the lock until %s. Clear it with: php laika schedule:unlock --id=%s',
                \date('H:i:s', $until),
                $schedule->id(),
            );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
    }
}
