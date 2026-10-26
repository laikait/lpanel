<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * One scheduled task: what to run, when, and what must not happen twice.
 *
 *     $schedules->command('invoice:send-reminders')->dailyAt('02:00');
 *     $schedules->job(RecalculateBilling::class)->monthlyOn(1, '03:00')->onQueue('billing');
 *     $schedules->call('customer:cleanup', static fn (Customers $c) => $c->purge())->weekly();
 *
 * A description, like Route and Command are. It is recorded while a module
 * loads and interrogated later by the scheduler; nothing here runs anything.
 *
 * **Every interval is a cron expression.** everyFiveMinutes() sets "star/5 star
 * star star star" and nothing else -- there is no second scheduling engine
 * measuring elapsed time. That matters more than the saved code: an
 * elapsed-time scheduler needs to remember when each task last ran, which means
 * durable state that can be lost, restored stale, or disagree between two
 * machines. A cron expression is a pure function of the clock, so two
 * processes asking "is this due" in the same minute always agree, and a machine
 * that was switched off simply missed that minute rather than firing a backlog
 * the moment it comes back.
 *
 * The cost is honest and worth stating: nothing finer than a minute, because
 * the process that asks is started by cron once a minute. A task that must run
 * every ten seconds needs a resident process, which this framework does not
 * have and will not pretend to.
 *
 * **Overlap protection is on by default.** A schedule holds a lock keyed by its
 * id for as long as it runs, so a task that overruns its own interval is
 * skipped rather than started twice. Off is available and is the unusual
 * choice: the cost of a skip is one missed run, and the cost of an overlap is
 * two processes writing the same invoices.
 */
final class Schedule
{
    /**
     * The same rule a queue name is held to, plus the colon a command name uses.
     *
     * It becomes a lock key and therefore a filename, which is the whole reason
     * it is checked at all.
     */
    public const ID_PATTERN = '/^[a-z][a-z0-9]*([:._-][a-z0-9]+)*$/';

    public const DEFAULT_EXPRESSION = '0 0 * * *';

    private string $id;

    private string $description = '';

    private CronExpression $expression;

    private ?string $timezone = null;

    private ?int $lockSeconds = null;

    private ?string $queue = null;

    private ?\Closure $guard = null;

    /**
     * @param mixed        $handler   a command name, a job class-string, or a closure
     * @param list<string> $arguments console arguments, for a command target only
     */
    public function __construct(
        string $id,
        public readonly ScheduleTarget $target,
        public readonly mixed $handler,
        public readonly array $arguments = [],
        public readonly ?string $module = null,
    ) {
        $this->id = $this->assertIdentifier($id);
        $this->expression = new CronExpression(self::DEFAULT_EXPRESSION);
    }

    // ---- identity ---------------------------------------------------------

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Override the id derived from the target.
     *
     * Needed when one command is scheduled twice -- a nightly run and a Monday
     * morning one -- because the id is the lock key and two schedules sharing
     * it would take each other's lock.
     */
    public function identify(string $id): self
    {
        $this->id = $this->assertIdentifier($id);

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function description(): string
    {
        return $this->description;
    }

    // ---- when -------------------------------------------------------------

    public function cron(string $expression): self
    {
        $this->expression = new CronExpression($expression);

        return $this;
    }

    public function expression(): CronExpression
    {
        return $this->expression;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron(\sprintf('%d * * * *', $minute));
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = $this->clock($time);

        return $this->cron(\sprintf('%d %d * * *', $minute, $hour));
    }

    /** @param int $day 0 is Sunday, as in cron */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        [$hour, $minute] = $this->clock($time);

        return $this->cron(\sprintf('%d %d * * %d', $minute, $hour, $day));
    }

    public function weekly(): self
    {
        return $this->weeklyOn(0);
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = $this->clock($time);

        return $this->cron(\sprintf('%d %d %d * *', $minute, $hour, $day));
    }

    public function monthly(): self
    {
        return $this->monthlyOn();
    }

    /**
     * Run this task on this timezone's clock rather than the application's.
     *
     * An ERP that bills in two countries has month ends in two places, and
     * "midnight" is the wrong answer to at least one of them. Worth saying once
     * here: over a daylight-saving boundary a wall clock repeats an hour and
     * skips another, so a task that must run exactly once and never twice
     * belongs on UTC -- which is what it gets unless something says otherwise.
     */
    public function timezone(string $timezone): self
    {
        if (!\in_array($timezone, \timezone_identifiers_list(), true)) {
            throw SchedulerException::unknownTimezone($this->id, $timezone);
        }

        $this->timezone = $timezone;

        return $this;
    }

    public function timezoneName(): ?string
    {
        return $this->timezone;
    }

    /** Is this the minute, on this schedule's own clock? */
    public function isDue(\DateTimeImmutable $at, string $fallbackTimezone = 'UTC'): bool
    {
        $zone = $this->timezone ?? $fallbackTimezone;

        return $this->expression->isDue($at->setTimezone(new \DateTimeZone($zone)));
    }

    public function nextRunAfter(\DateTimeImmutable $at, string $fallbackTimezone = 'UTC'): ?\DateTimeImmutable
    {
        $zone = $this->timezone ?? $fallbackTimezone;

        return $this->expression->nextRunAfter($at->setTimezone(new \DateTimeZone($zone)));
    }

    // ---- what must not happen twice ---------------------------------------

    /**
     * Hold a lock for the length of the run. On by default.
     *
     * The argument is how long the lock may outlive the process holding it. It
     * exists because a worker killed with SIGKILL leaves no chance to release
     * anything, and a lock with no expiry would stop this task forever: the
     * cost of a crash has to be a bounded number of skipped runs, not a silent
     * permanent stop. Set it a little above the longest run this task has, not
     * far above.
     */
    public function withoutOverlapping(int $seconds = 3600): self
    {
        $this->lockSeconds = \max(1, $seconds);

        return $this;
    }

    /**
     * Let this task run alongside itself.
     *
     * Correct for something genuinely idempotent and quick. It is not the
     * default, because the failure it allows -- two processes recalculating the
     * same ledger -- is discovered in the numbers rather than in a log.
     */
    public function allowOverlapping(): self
    {
        $this->lockSeconds = 0;

        return $this;
    }

    public function locksFor(int $default = 3600): int
    {
        return $this->lockSeconds ?? \max(1, $default);
    }

    public function guardsAgainstOverlap(int $default = 3600): bool
    {
        return $this->locksFor($default) > 0;
    }

    // ---- the queue --------------------------------------------------------

    /**
     * Which queue a scheduled job goes on.
     *
     * Only a job target has one. A command and a callback run in the
     * scheduler's own process; putting either on a queue would mean serialising
     * a console invocation, and the declaration is refused rather than quietly
     * ignored.
     */
    public function onQueue(?string $queue = null): self
    {
        if ($this->target !== ScheduleTarget::Job) {
            throw SchedulerException::queueingWhatIsNotAJob($this->id);
        }

        $this->queue = $queue;

        return $this;
    }

    public function queue(): ?string
    {
        return $this->queue;
    }

    // ---- conditions -------------------------------------------------------

    /**
     * Run only when this returns true.
     *
     * Called through the container, so it takes whatever it needs as
     * parameters. This is where "only on the primary node" and "only while the
     * feature is on" live -- conditions that belong to the application and
     * would otherwise become configuration keys the framework has to invent.
     *
     * It must return true, not merely something truthy. A condition answers one
     * question, and a callback that forgets to return should not be read as
     * consent to run the billing.
     */
    public function when(\Closure $condition): self
    {
        $this->guard = $condition;

        return $this;
    }

    public function condition(): ?\Closure
    {
        return $this->guard;
    }

    // ---- introspection ----------------------------------------------------

    /** The one-line form: what this actually runs. */
    public function summary(): string
    {
        return match ($this->target) {
            ScheduleTarget::Command => \trim(\implode(' ', [
                \is_string($this->handler) ? $this->handler : '?',
                ...$this->arguments,
            ])),
            ScheduleTarget::Job => \sprintf(
                'queue %s on "%s"',
                \is_string($this->handler) ? $this->handler : '?',
                $this->queue ?? 'default',
            ),
            ScheduleTarget::Callback => 'a callback',
        };
    }

    /**
     * HH:MM, checked.
     *
     * @return array{int, int}
     */
    private function clock(string $time): array
    {
        if (\preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', \trim($time), $parts) !== 1) {
            throw SchedulerException::unusableTime($this->id, $time);
        }

        return [(int) $parts[1], (int) $parts[2]];
    }

    private function assertIdentifier(string $id): string
    {
        return \preg_match(self::ID_PATTERN, $id) === 1
            ? $id
            : throw SchedulerException::unusableIdentifier($id);
    }
}
