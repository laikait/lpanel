<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Input;
use App\Engine\Queue\Job;

/**
 * The scheduling API a module receives.
 *
 *     $module->schedules(static function (ScheduleCollector $schedules): void {
 *         $schedules->command('invoice:send-reminders')->dailyAt('02:00');
 *         $schedules->job(RecalculateBilling::class)->monthly()->onQueue('billing');
 *         $schedules->call('customer:cleanup', $purge(...))->weeklyOn(0, '04:00');
 *     });
 *
 * What RouteCollector is for routes and CommandCollector is for commands, and
 * for the same reason: the module that owns a capability owns when it runs.
 * There is no central schedule file listing everything the application does at
 * night -- installing a module brings its schedules with it, and removing the
 * module takes them away.
 *
 * Everything is checked here, while the module loads. A command name that is
 * not registered, a class that is not a job, a job whose constructor needs
 * arguments it will never be given -- all of them stop the application from
 * starting rather than becoming a line in a log at 2am that nobody reads. That
 * is the single most valuable property a scheduler can have: the code runs when
 * nobody is watching, so the checking has to happen when somebody is.
 */
final class ScheduleCollector
{
    public function __construct(
        private readonly ScheduleRegistry $registry,
        private readonly CommandRegistry $commands,
        private readonly ?string $module = null,
    ) {}

    /**
     * Run a console command.
     *
     * The shape the specification's own examples take -- invoice:send-reminders,
     * customer:cleanup, billing:recalculate -- and the reason is that a command
     * is already the way a person runs that work by hand. Scheduling it means
     * the thing that runs at night and the thing an operator runs while
     * debugging are the same code path, which is not true of a scheduler with
     * its own task type.
     *
     * It is dispatched in this process, not shelled out to another PHP. See
     * Scheduler::runCommand() for what that buys and what it costs.
     */
    public function command(string $name, string ...$arguments): Schedule
    {
        $command = $this->commands->get($name);

        if ($command === null) {
            throw SchedulerException::unknownCommand($name, $name);
        }

        // Parsed here and thrown away, purely so that a misspelt option is a
        // boot-time ConsoleException naming the option rather than a 2am
        // failure. Parsing needs the declaration, so this is the first moment
        // it can be done at all -- and the scheduler parses it again when it
        // runs, which costs nothing next to being sure it can.
        Input::parse($command, \array_values($arguments));

        return $this->registry->add(new Schedule(
            $name,
            ScheduleTarget::Command,
            $name,
            \array_values($arguments),
            $this->module,
        ));
    }

    /**
     * Queue a job.
     *
     * This is the specification's "should eventually work with the queue
     * system", and it is one line rather than a subsystem because the queue
     * already answers the hard part. The scheduler pushes; where the job then
     * runs is the queue's configuration. Under the default sync store it runs
     * inline in the scheduler's process, which is exactly the behaviour that
     * makes an application work on a machine with no worker.
     *
     * The job is built with no arguments, because a schedule has nothing to
     * tell it. A job that needs data belongs on a queue, pushed by whatever
     * knows the data -- and a constructor that requires arguments is refused
     * here rather than discovered at three in the morning.
     *
     * @param class-string $class
     */
    public function job(string $class): Schedule
    {
        $id = 'job:' . $this->kebab($class);

        if (!\is_subclass_of($class, Job::class)) {
            throw SchedulerException::notAJob($id, $class);
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw SchedulerException::jobNeedsArguments($id, $class);
        }

        return $this->registry->add(new Schedule(
            $id,
            ScheduleTarget::Job,
            $class,
            [],
            $this->module,
        ));
    }

    /**
     * Call a closure, through the container.
     *
     * The id is given rather than derived: a closure has no name, and the id is
     * the lock key, so it is not something to invent on a caller's behalf.
     */
    public function call(string $id, \Closure $callback): Schedule
    {
        return $this->registry->add(new Schedule(
            $id,
            ScheduleTarget::Callback,
            $callback,
            [],
            $this->module,
        ));
    }

    /** RecalculateBilling becomes recalculate-billing. */
    private function kebab(string $class): string
    {
        $short = \substr((string) \strrchr('\\' . $class, '\\'), 1);
        $spaced = \preg_replace('/(?<!^)[A-Z]/', '-$0', $short);

        return \strtolower($spaced ?? $short);
    }
}
