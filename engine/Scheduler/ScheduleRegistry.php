<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * Every schedule the application has, in the order its modules declared them.
 *
 * Registration order is module order, which is deterministic -- so two runs of
 * `schedule:list` on two machines print the same list in the same sequence, and
 * a diff between them is a real difference rather than filesystem noise.
 *
 * A list rather than a map keyed by id, which looks like the weaker choice and
 * is not. A schedule is registered the moment it is created and then configured
 * fluently, so its id is not final until the declaring closure returns -- the
 * whole point of ->identify() is to change it. Keying on the way in would mean
 * indexing by a value that is still allowed to change, which is how a map ends
 * up disagreeing with the objects in it.
 *
 * Uniqueness is still enforced, by assertUnique() once the replay is done. It
 * is enforced loudly, because an id is the lock key: two schedules sharing one
 * would take each other's lock and each would look, from the other's point of
 * view, like a run that is still going. Silently renaming the second is the one
 * thing that must not happen, since the symptom appears weeks later as a task
 * that has quietly stopped.
 */
final class ScheduleRegistry
{
    /** @var list<Schedule> */
    private array $schedules = [];

    public function add(Schedule $schedule): Schedule
    {
        $this->schedules[] = $schedule;

        return $schedule;
    }

    /**
     * Refuse two schedules with the same id.
     *
     * Called by the module manager once every module's schedules have been
     * replayed, which is the first moment every id is final and the last moment
     * before anything could run.
     */
    public function assertUnique(): void
    {
        $seen = [];

        foreach ($this->schedules as $schedule) {
            $id = $schedule->id();

            if (\array_key_exists($id, $seen)) {
                throw SchedulerException::duplicateIdentifier($id, $seen[$id], $schedule->module);
            }

            $seen[$id] = $schedule->module;
        }
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    public function get(string $id): ?Schedule
    {
        foreach ($this->schedules as $schedule) {
            if ($schedule->id() === $id) {
                return $schedule;
            }
        }

        return null;
    }

    /** @return list<Schedule> */
    public function all(): array
    {
        return $this->schedules;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return \array_map(static fn(Schedule $schedule): string => $schedule->id(), $this->schedules);
    }

    public function count(): int
    {
        return \count($this->schedules);
    }

    /**
     * Everything that should run at this moment.
     *
     * "This moment" is a minute, not an instant, which is the whole contract
     * with cron: the process is started once a minute and anything whose
     * expression matches that minute runs. A run that starts three seconds late
     * finds the same set as one that starts on the second.
     *
     * @return list<Schedule>
     */
    public function due(\DateTimeImmutable $at, string $timezone = 'UTC'): array
    {
        $due = [];

        foreach ($this->schedules as $schedule) {
            if ($schedule->isDue($at, $timezone)) {
                $due[] = $schedule;
            }
        }

        return $due;
    }
}
