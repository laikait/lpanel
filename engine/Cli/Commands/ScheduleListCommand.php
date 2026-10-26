<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Scheduler\Schedule;
use App\Engine\Scheduler\Scheduler;

/**
 * What this application does when nobody is watching.
 *
 * The one place that answers it. Schedules are declared across every installed
 * module, so without this the only way to know what runs at 3am is to read
 * every module.php -- and the question is usually being asked at 3am by
 * somebody who has just been paged.
 *
 * Next-run times are computed rather than remembered, because the expression is
 * a pure function of the clock. Nothing has to have run for this to be right,
 * which means it answers correctly on a machine where the cron line was never
 * installed -- the failure it is most often used to find.
 */
final class ScheduleListCommand
{
    public function __construct(private readonly Scheduler $scheduler) {}

    public function __invoke(Output $output, ?string $module = null, bool $due = false): int
    {
        $schedules = $this->scheduler->schedules()->all();

        if ($schedules === []) {
            $output->warning('No schedules are registered.');
            $output->line();
            $output->line('A module declares them with $module->schedules(...). Nothing is scanned for,');
            $output->line('so an empty list here means nothing has asked to run.');

            return 0;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->scheduler->timezone()));
        $held = $this->scheduler->lock()->held();

        $rows = [];
        $shown = 0;

        foreach ($schedules as $schedule) {
            if ($module !== null && $schedule->module !== $module) {
                continue;
            }

            $isDue = $schedule->isDue($now, $this->scheduler->timezone());

            if ($due && !$isDue) {
                continue;
            }

            ++$shown;
            $rows[] = [
                $schedule->id(),
                $schedule->expression()->describe(),
                $this->next($schedule, $now, $isDue),
                $this->state($schedule, $held),
                $schedule->module ?? '-',
            ];
        }

        $output->pairs([
            'Clock' => $this->scheduler->timezone() . ', now ' . $now->format('Y-m-d H:i'),
            'Locks' => $this->scheduler->lock()->describe(),
            'Schedules' => \sprintf('%d registered, %d shown', \count($schedules), $shown),
        ]);

        $output->line();

        if ($rows === []) {
            $output->warning('Nothing matches.');

            return 0;
        }

        $output->table(['Schedule', 'When', 'Next', 'State', 'Module'], $rows);

        $output->line();
        $output->line('Nothing here runs on its own. One cron line drives all of it:');
        $output->line('    * * * * *  cd ' . \getcwd() . ' && php laika schedule:run');

        return 0;
    }

    private function next(Schedule $schedule, \DateTimeImmutable $now, bool $isDue): string
    {
        if ($isDue) {
            return 'now';
        }

        $next = $schedule->nextRunAfter($now, $this->scheduler->timezone());

        if ($next === null) {
            // An expression such as "0 0 30 2 *" parses and can never match.
            return 'never';
        }

        return $next->format('Y-m-d H:i');
    }

    /** @param list<string> $held */
    private function state(Schedule $schedule, array $held): string
    {
        if (\in_array($schedule->id(), $held, true)) {
            return 'running';
        }

        return $schedule->guardsAgainstOverlap() ? 'idle' : 'idle (overlaps)';
    }
}
