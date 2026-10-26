# Scheduler

A *schedule* is work that runs at a set time: nightly invoices, an hourly
cleanup, a monthly report. Modules declare them; one cron line runs them all.

## Declare a schedule

```php
// modules/Example/module.php
$module->schedules(static function (ScheduleCollector $schedules): void {
    $schedules->command('invoice:send-reminders')->dailyAt('02:00');
    $schedules->job(RecalculateBilling::class)->monthlyOn(1, '03:00')->onQueue('billing');
    $schedules->call('customer:cleanup', $purge(...))->weeklyOn(0, '04:00');
});
```

**The machine gets one cron line, and it never changes:**

```cron
* * * * *  cd /var/www/app && php laika schedule:run >> /dev/null 2>&1
```

`php laika system:cron:install` writes that line for you. On systemd, a timer can
replace it: `php laika system:systemd:install` (see
[Running](../operations/running.md#or-a-systemd-timer)) — one or the other,
never both.

That trade is the whole point. A crontab is edited over ssh by whoever has shell
access: it is not in version control, not installed by a deployment, and
invisible to everyone who wrote the code. Moving the decision into `module.php`
makes a schedule reviewable in a diff, testable in CI, installed with the module
that needs it, and **gone when that module is removed**. What stays on the
machine is one line that says "ask the application".

`schedule:run` exits as soon as it has run what is due. It is not a daemon and
does not want supervising — nothing in `engine/Scheduler` sleeps, loops on the
clock, or starts a process, and an architecture test keeps it that way.

## Three things you can schedule

| Call | Runs |
|---|---|
| `command('name', '--flag')` | a console command, in this process |
| `job(Class::class)` | a job, pushed onto the queue |
| `call('id', $closure)` | a closure, through the container, with parameters injected |

**A command** is usually the right choice, because a command is already how a
person runs that work by hand. Scheduling one means the thing that runs at night
and the thing an operator runs while debugging are the same code path, which is
not true of a scheduler with its own task type.

**A job** is one line because the queue already answers the hard part: the
scheduler pushes and returns, and where the work actually happens is the queue's
configuration. Under the default `sync` store it runs inline and nothing is lost.

**A long task should be a job**, for exactly that reason: a scheduled *command*
that takes ten minutes is ten minutes during which the process holding up every
other schedule is that one.

A scheduled job is built with no arguments, because a schedule has nothing to
tell it. A job whose constructor requires something is refused **at the line
that scheduled it**, not at three in the morning.

**There is no shell-command target.** Shelling out means locating a PHP binary,
quoting a command line for two operating systems, and losing the container, the
configuration and the log this process already built.

## When it runs

```php
->everyFiveMinutes()   // */5 * * * *
->hourlyAt(20)         // 20 * * * *
->dailyAt('02:30')     // 30 2 * * *
->weeklyOn(1, '09:00') // 0 9 * * 1
->monthlyOn(1)         // 0 0 1 * *
->cron('15,45 9-17 * * 1-5')
```

**Every one of those sets a cron expression and nothing else.** There is no
second scheduling engine measuring elapsed time, and that matters more than the
saved code: an elapsed-time scheduler has to remember when each task last ran,
which is durable state that can be lost, restored stale, or disagree between two
machines.

A cron expression is a pure function of the clock. Two processes asking "is this
due" in the same minute always agree, and **a machine that was switched off
simply missed that minute** rather than firing a backlog when it comes back.

Cron is also the notation operations already reads, so an expression pasted out
of a real crontab means here what it meant there — including the rule that
surprises everyone once: **with both day fields restricted they are ORed**, so
`0 0 13 * FRI` is "the 13th, and also every Friday", not "Friday the 13th".

The honest cost: nothing finer than a minute, because the process that asks is
started once a minute. A task that must run every ten seconds needs a resident
process, which this framework does not have and will not pretend to.

### Time zones

`scheduler.timezone`, falling back to `app.timezone`, and `->timezone()` per
task — because an ERP that bills in two countries has month ends in two places.

Over a daylight-saving boundary a wall clock repeats an hour and skips another,
so **a task that must run exactly once and never twice belongs on UTC**, which
is what it gets unless something says otherwise.

## Not twice

**Overlap protection is on by default.** A task holds a lock keyed by its id
while it runs, so one that overruns its own interval is skipped rather than
started beside itself.

The cost of a skip is one missed run; the cost of an overlap is two processes
writing the same invoices. So the safe way round is the default. A task that
genuinely may overlap says `->allowOverlapping()`, where the decision sits next
to the task it affects and shows up in `schedule:list`.

The lock is one system call: creating a file with `x` mode opens it `O_EXCL`,
which either creates the file or fails, atomically, on NTFS and on every POSIX
filesystem — the same argument the file queue store makes with `rename()`.

**A lock expires**, because a process killed outright releases nothing, and a
lock with no expiry would stop its task permanently and silently. A crash costs
a bounded number of skipped runs. Set `->withoutOverlapping($seconds)` a little
above the task's longest run, not far above.

```bash
php laika schedule:list                       # what runs, when, and what is running now
php laika schedule:run                        # what cron calls
php laika schedule:run --id=<id> --force      # run one now; still takes the lock
php laika schedule:unlock --id=<id>           # after a machine died mid-run
```

`--force` ignores the clock and nothing else. "Run this now" is a decision about
timing; "this may run beside a copy of itself" is a decision about safety, and
conflating them would let somebody debugging a task at their desk start a second
copy of the one already running.

**There is no lock that does not lock.** `CacheStore` has a null store and
`QueueStore` has a sync store, because "do not cache" and "run it here" are
things people legitimately want. The equivalent here has exactly one behaviour —
two copies of a task at once — and shipping it would make the most dangerous
configuration the easiest to reach. An architecture test refuses one.

## Where the output goes

Three hooks: `schedule.started`, `schedule.finished` (**every** outcome,
carrying a `ScheduleResult`) and `schedule.failed` (carrying the exception).

`Logging\ScheduleLog` is an ordinary listener on the second one, and is meant to
be deletable. `engine/Scheduler` contains no reference to a logger, and a test
enforces it.

That listener earns its place more plainly than most. Work that runs at three in
the morning has no operator and no response: if it is not written down, nothing
happened as far as anybody can tell. The ordinary failure of cron is that a
command's output goes to a `MAILTO` nobody set, so **the scheduler captures what
each task printed** and hands it to the log with everything else.

Levels say what happened, so a log filtered to warnings and above still shows
the right things:

| Outcome | Level |
|---|---|
| failed | error |
| skipped (a lock was held) | notice |
| ran | info |

Skipped is deliberately its own outcome. It is neither a success nor a failure,
and collapsing it into either is how a schedule that has silently stopped
keeping up looks fine on a dashboard.

**One failing task does not stop the run**, and `schedule:run` exits 1 when
anything failed, so the cron line itself can be monitored without parsing a log.

## Checked when somebody is watching

An unknown command name, a misspelt option, a class that is not a job, a
malformed expression, two schedules sharing an id — all of them stop the
application from starting.

It is the single most valuable property a scheduler can have: the code runs when
nobody is looking, so the checking has to happen when somebody is.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Nothing runs at all | The cron line is missing | `php laika system:cron:install` |
| Everything runs twice | `schedule:run` is in cron on two machines | It belongs on exactly one |
| A task stopped running after a crash | Its lock is still held | `php laika schedule:unlock --id=<id>` |
| A task's output is nowhere | It goes to the log, and no writer is configured | See [Logging](logging.md) |
| A schedule is skipped every time | The previous run overruns the interval | Make it a job, or lengthen the interval |
| The application will not start | A bad command name, expression or duplicate id | The message names it |
| A task fires twice over a clock change | It is on a wall clock through a DST boundary | Put it on UTC |
