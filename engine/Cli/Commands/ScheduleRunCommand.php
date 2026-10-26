<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Scheduler\ScheduleOutcome;
use App\Engine\Scheduler\Scheduler;
use App\Engine\Scheduler\ScheduleResult;

/**
 * The command one cron line calls, once a minute, forever.
 *
 *     * * * * *  cd /var/www/app && php laika schedule:run >> /dev/null 2>&1
 *
 * It exits as soon as it has run what is due; it is not a daemon and does not
 * want to be supervised. Discarding its output in the crontab is safe here and
 * is not the usual mistake, because the scheduler captures what each task
 * printed and hands it to the log -- see Logging\ScheduleLog.
 *
 * The exit code is 1 when any task failed, so a monitoring system can watch the
 * cron line rather than parse a log. That is the same contract queue:work has,
 * for the same reason: a shell script cannot ask what was meant.
 */
final class ScheduleRunCommand
{
    public function __construct(private readonly Scheduler $scheduler) {}

    public function __invoke(
        Output $output,
        ?string $id = null,
        bool $force = false,
        bool $dryRun = false,
    ): int {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->scheduler->timezone()));

        if ($id !== null) {
            return $this->one($output, $id, $now, $force, $dryRun);
        }

        $due = $this->scheduler->due($now);

        $output->pairs([
            'Clock' => $this->scheduler->timezone() . ', now ' . $now->format('Y-m-d H:i'),
            'Registered' => (string) $this->scheduler->schedules()->count(),
            'Due' => (string) \count($due),
        ]);

        if ($due === []) {
            $output->line();
            $output->success('Nothing is due this minute.');

            return 0;
        }

        $output->line();

        if ($dryRun) {
            foreach ($due as $schedule) {
                $output->line(\sprintf('  would run %s  (%s)', $schedule->id(), $schedule->summary()));
            }

            $output->line();
            $output->warning('Nothing ran: --dry-run.');

            return 0;
        }

        $failed = 0;

        foreach ($due as $schedule) {
            $result = $this->scheduler->runOne($schedule);
            $this->report($output, $result);

            if ($result->failed()) {
                ++$failed;
            }
        }

        $output->line();

        if ($failed > 0) {
            $output->error(\sprintf('%d of %d failed.', $failed, \count($due)));

            return 1;
        }

        $output->success(\sprintf('%d ran.', \count($due)));

        return 0;
    }

    /**
     * One schedule by id.
     *
     * --force ignores the clock and nothing else. The lock is still taken,
     * because "run this now" is a decision about timing and "this may run
     * beside a copy of itself" is a decision about safety; conflating them
     * would mean an operator debugging a task at their desk could start a
     * second copy of the one already running.
     */
    private function one(
        Output $output,
        string $id,
        \DateTimeImmutable $now,
        bool $force,
        bool $dryRun,
    ): int {
        $schedule = $this->scheduler->schedules()->get($id);

        if ($schedule === null) {
            $output->error(\sprintf('No schedule is called "%s". See: php laika schedule:list', $id));

            return 1;
        }

        if (!$force && !$schedule->isDue($now, $this->scheduler->timezone())) {
            $output->warning(\sprintf('%s is not due (%s).', $id, $schedule->expression()->describe()));
            $output->line('Run it anyway with --force.');

            return 0;
        }

        if ($dryRun) {
            $output->line(\sprintf('  would run %s  (%s)', $schedule->id(), $schedule->summary()));
            $output->line();
            $output->warning('Nothing ran: --dry-run.');

            return 0;
        }

        $result = $this->scheduler->runOne($schedule);
        $this->report($output, $result);

        return $result->failed() ? 1 : 0;
    }

    private function report(Output $output, ScheduleResult $result): void
    {
        $line = \sprintf(
            '  %-9s %-32s %5dms %s',
            $result->outcome->value,
            $result->id(),
            $result->milliseconds(),
            $result->message,
        );

        match ($result->outcome) {
            ScheduleOutcome::Failed => $output->error(\rtrim($line)),
            ScheduleOutcome::Skipped => $output->warning(\rtrim($line)),
            default => $output->line(\rtrim($line)),
        };

        // What the task itself printed, indented under its own line. It is
        // shown here and logged as well: an operator watching this run should
        // not have to open the log to see the thing they just ran say why it
        // went wrong.
        foreach (\explode("\n", \trim($result->output)) as $printed) {
            if (\trim($printed) !== '') {
                $output->line('      | ' . \rtrim($printed));
            }
        }
    }
}
