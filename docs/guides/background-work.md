# Background work

Some work should not make a visitor wait: sending an email, calling a slow outside
service, recalculating totals. This guide shows how to:

1. write that work as a **job**,
2. **queue** it, so it runs later,
3. run a **worker**, the process that does queued jobs,
4. handle jobs that fail,
5. run work on a **schedule**, such as every night at 02:00.

Both jobs and schedules are declared in your module, and you need nothing extra
installed to start.

Reference: [Queue and worker](../reference/queue.md) and
[Scheduler](../reference/scheduler.md). Running workers and cron on a server is in
[Running in production](../operations/running.md).

## Step 1: write a job

A **job** is a class in your module's `Jobs/` folder. It implements `Job` and has
a `handle()` method that does the work:

```php
final class AcknowledgeMessage implements Job
{
    public function __construct(private readonly int $messageId) {}

    public function handle(Logger $log): void
    {
        $log->info('Acknowledging a message', ['id' => $this->messageId]);
    }
}
```

A job may run minutes later, in a different process. Two rules make that safe:

- **The constructor takes plain data**: ids, strings, numbers. This data is what
  gets stored in the queue. Pass an id, not a whole model: the record may change
  before the job runs, and the job should read it fresh.
- **`handle()` takes services**, such as a logger or a repository. The framework
  passes them in when the job runs. They are never stored in the queue.

There is nothing to register. The class is enough.

## Step 2: queue it

Ask for `Queue` in a constructor, then push jobs onto it:

```php
public function __construct(private readonly Queue $queue) {}

$this->queue->push(new AcknowledgeMessage($id));
$this->queue->later(3600, new ChaseInvoice($id));              // in an hour
$this->queue->push(new RecalculateBilling(), queue: 'billing');
```

- `push()` queues a job to run as soon as possible.
- `later(3600, ...)` waits at least 3600 seconds first.
- `queue: 'billing'` puts it on a separate queue named `billing`, so a worker
  can handle billing work on its own. Without it, jobs go on `default`.

Email is the commonest thing to send later, and needs no job class of your own:
`$mailer->queue($message)` queues it. See [Mail](../reference/mail.md#sending-later).

## Step 3: choose where jobs wait, and start a worker

The setting `QUEUE_STORE` decides where queued jobs wait. Your `push()` code stays
the same whichever you choose:

| `QUEUE_STORE` | What happens |
|---|---|
| `sync` (the default) | The job runs **immediately**, inside `push()`. If it throws, the exception reaches the code that called `push()`. |
| `file` | The job waits in a file, until a worker runs it. For one server. |
| `database` | The job waits in a database table, until a worker runs it. For one server or several. |
| `memory` | The job is kept in memory until the process ends; a worker in another process never sees it. For tests. |

**Start with `sync`.** Everything works; it just does not happen in the
background. When a request should stop waiting for the work, switch to `file`
(or `database`) and start a worker:

```bash
QUEUE_STORE=file
php laika queue:work --queue=default --max-jobs=500 --max-time=3600
php laika queue:status
```

- `QUEUE_STORE=file` goes in your `.env` file.
- **`queue:work`** is the worker. It keeps running and does jobs as they arrive.
  `--max-jobs` and `--max-time` make it stop after 500 jobs or an hour, so it
  restarts fresh (your process manager starts it again; see
  [Running in production](../operations/running.md)).
- **`queue:status`** shows how many jobs are waiting.

For `database`, run `php laika migrate` once first: it creates the `jobs` table.
Use `database` when your application runs on several servers, so that workers on
any of them take jobs from the same table. See
[Queue and worker](../reference/queue.md).

## Step 4: when a job fails

If `handle()` throws, the job is **tried again** after a delay that grows each
time: 5 seconds, then 10, 20, and so on, up to 10 minutes. After `QUEUE_TRIES`
attempts (3 by default) it gives up, and the job is moved to the **failed list**,
together with the error:

```bash
php laika queue:failed                 # what gave up, and why
php laika queue:failed --retry=<id>    # try again, attempts reset
php laika queue:failed --retry-all
php laika queue:failed --forget=<id>
```

> **Make every job safe to run twice.** If a worker is killed in the middle of a
> job, the job becomes available again after `QUEUE_TIMEOUT` seconds and runs
> again. So, for example, check whether the email was already sent before
> sending it.

To be told when a job fails, listen to the hook `job.failed` in `module.php`:

```php
$module->hook('job.failed', [QueueAlerts::class, 'onFailed']);
```

`QueueAlerts::onFailed()` is your own static method. It receives the exception,
the queued job, and whether the job will be tried again.

Other hooks you can listen to: `job.queued` fires on every `push()`, and
`job.started` and `job.finished` fire wherever the job runs. With `sync` there is
no worker, so there is no `job.failed`: the exception goes straight to the code
that called `push()`.

## Step 5: run work on a schedule

A **schedule** runs something at set times. Declare schedules in `module.php`,
next to the code they run:

```php
$module->schedules(static function (ScheduleCollector $schedules): void {
    // A command: the same thing an operator runs by hand.
    $schedules->command('customer:sync', '--limit=100')
        ->describe('Pull anything the upstream system changed overnight.')
        ->dailyAt('02:00')
        ->withoutOverlapping(1800);

    // A job: pushed onto the queue; where it runs is the queue's business.
    $schedules->job(PurgeSpam::class)
        ->describe('Delete messages marked as spam.')
        ->dailyAt('03:00');

    // A closure, with its parameters injected.
    $schedules->call('customer:refresh-counts', static function (CustomerQuery $customers): void {
        $customers->forgetTotal();
    })->everyThirtyMinutes()->allowOverlapping();
});
```

Three kinds of task:

| Kind | Declared with | Runs |
|---|---|---|
| a console command | `command('customer:sync', '--limit=100')` | that command, as if typed |
| a job | `job(PurgeSpam::class)` | pushes the job onto the queue |
| a function | `call('name', function ...)` | the function, with services passed in |

### The one cron line

**cron** is the Linux service that runs commands at set times. The server needs
**one** cron line, added once, however many schedules your modules declare:

```cron
* * * * *  cd /var/www/app && php laika schedule:run >> /dev/null 2>&1
```

It runs `schedule:run` every minute. `schedule:run` checks which tasks are due,
and runs them. Replace `/var/www/app` with your project's folder.

### How often

| Method | Runs |
|---|---|
| `everyFiveMinutes()`, `everyThirtyMinutes()` | every 5 or 30 minutes |
| `hourlyAt(20)` | every hour, at 20 minutes past |
| `dailyAt('02:30')` | every day at 02:30 |
| `weeklyOn(1, '09:00')` | every Monday at 09:00 (0 is Sunday) |
| `monthlyOn(1)` | on the 1st of every month |
| `cron('15,45 9-17 * * 1-5')` | any cron expression; this one is at :15 and :45, 09:00–17:59, Monday to Friday |

Nothing runs more often than once a minute.

### Things to know

- **A scheduled job is created with no arguments**, because a schedule has no
  data to give it. `PurgeSpam` has no constructor parameters.
- **A task is never run twice at the same time.** If it is still running when its
  next turn comes, that turn is skipped. The number in
  `withoutOverlapping(1800)` is how many seconds the "still running" lock lasts if
  the process crashes. Make it a little longer than the task ever takes.
- **Put long work in a job, not a command.** Tasks due in the same minute run one
  after another, so one slow command delays all the rest.
- **Times are UTC** unless `SCHEDULER_TIMEZONE`, `APP_TIMEZONE` or `->timezone()`
  says otherwise. Keep anything that must run exactly once a day on UTC: where
  clocks change for summer time, one hour a year happens twice.
- **Mistakes stop the application from starting**: an unknown command, a job
  class that is not a job, or a wrong cron expression. You find out when you
  deploy, not at 3 in the morning.

```bash
php laika schedule:list                     # every task, and when it next runs
php laika schedule:run --id=<id> --force    # run one now, lock still honoured
```

## Test it

With `sync`, a pushed job runs inside the test, so you can check its effects
straight away.

To check **what** was queued, listen to `job.queued`. If the job should not run
at all during the test, start the application with
`['queue' => ['store' => 'memory']]`:

```php
$queued = [];
add_hook('job.queued', static function (mixed $envelope, Job $job) use (&$queued): void {
    $queued[] = $job;
});
```

See [Testing](testing.md).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Jobs are queued but never run | No worker is running, or it works on another queue. | Start `php laika queue:work`, with `--queue=<name>` if you used one. |
| A request still waits for the job | `QUEUE_STORE` is `sync`, the default. | Set `QUEUE_STORE=file` or `database`, and run a worker. |
| The worker runs old code after a deploy | It loaded the code when it started. | Restart workers on every deploy; `--max-time` restarts them regularly anyway. |
| A job ran twice | A worker was killed mid-job, and the job was released after `QUEUE_TIMEOUT`. | Make the job safe to run twice. |
| Nothing scheduled ever runs | The cron line is missing, or points at the wrong folder. | Add it; run `php laika schedule:run` by hand to test. |
| The application will not start after adding a schedule | A command name, job class or cron expression is wrong. | The error names it; fix the declaration. |

More in [Troubleshooting](../troubleshooting.md).
