# Queue and worker

A *job* is work the application puts off until later: sending an email, building
a report, calling a slow API. A *worker* is a process that runs jobs.

[Background work](../guides/background-work.md) builds one from nothing; this
page is the details.

## Write a job

```php
// modules/Example/Jobs/WelcomeCustomer.php
final class WelcomeCustomer implements Job
{
    public function __construct(private readonly int $customerId) {}

    public function handle(CustomerQuery $customers, Logger $log): void
    {
        ...
    }
}
```

```php
$this->queue->push(new WelcomeCustomer($customer->identity()));
$this->queue->later(3600, new ChaseInvoice($id), queue: 'billing');
```

A job is an ordinary class in a module's `Jobs/` folder. **Nothing registers
it** — the class name is the registration, and a worker in another process finds
it through the same autoloader everything else uses.

Look at where the two kinds of thing go:

- **The constructor takes data**, and that is what gets written to the queue.
- **`handle()` takes collaborators**, injected from the container of whatever
  process runs the job.

**Pass an id, not the model.** The customer may be edited between dispatching
and running, and reading it fresh is almost always what was meant. Serialising a
repository into a queue file and hoping its connection still works an hour later
is the failure this shape avoids by construction.

`Job` declares no methods, which is deliberate rather than lazy. An interface
method fixes its signature for every implementation, and `handle()`'s signature
is exactly the part that has to differ. A job with no `handle()` is refused when
it is **dispatched** instead — loudly, at the line that made the mistake.

## The stores

| Store | Runs jobs | Use when |
|---|---|---|
| `sync` | immediately, where they were dispatched | you have not set up a worker. **The default** |
| `file` | from `system/Queue`, by `queue:work` | one machine |
| `database` | from a table, by `queue:work` | any number of machines |
| `memory` | in this process only | tests and one-off batch commands |

**Why sync by default?** The alternatives fail quietly on a machine nobody has
set up yet: memory drops the job when the response is sent, and a file queue
holds it for a worker that may not exist. Running it inline is neither. An
application works out of the box, and deferring the work is a configuration
change plus a process — not an edit to the line that dispatches it.

**Under sync, an exception reaches the caller.** There is nothing to retry from —
the caller is still on the stack — and swallowing it would be pretending to be a
queue. A job that throws breaks the request that dispatched it, exactly as the
code would have if it had never been deferred.

**A Redis store is not built**, for the same reason the cache has none: the
extension is not installed here, and a store written blind would be unverified.
What makes adding one safe is `tests/Unit/Queue/StoreConformanceTest`, which
runs the same twenty assertions against every store — and an architecture test
fails if a store exists that it does not cover.

## Running a worker

```bash
php laika queue:work --queue=billing --max-jobs=100 --max-time=300
php laika queue:status
php laika queue:failed --retry=<id> | --retry-all | --forget=<id>
```

**Bounded runs are the point.** `--max-jobs` and `--max-time` exist so a worker
exits and something starts another. A worker that runs for a month is running
last month's deployment, with a month of memory growth and a database handle it
opened on Tuesday. Let it finish; let systemd, supervisor or a container restart
policy start a fresh one. `php laika system:systemd:install` writes and starts the
systemd units, one worker per queue (see
[Running](../operations/running.md#queue-workers)).

**Memory is a bound too.** `--memory=128M` (or `QUEUE_MAX_MEMORY`) stops the
worker, between jobs, once the process uses that much. With neither set it is
80% of `memory_limit` (`MEMORY_LIMIT`), so a worker whose memory grows exits
cleanly instead of PHP killing it in the middle of a job. It is checked after
each job, never during one, so leave room below `memory_limit` for the largest
job. The last line says what stopped it: `Worker stopped: memory (130M used).`

`queue:work` exits 1 when it gave up on a job, so a cron line that drains a
queue can be alerted on without parsing its output.

## Retry, backoff, failure

A job that throws goes back on the queue with a delay. When its attempts are
gone it moves to the failed list, with the error that killed it.

**Nothing inspects the exception to decide.** A worker that tried to tell a
transient failure from a permanent one would be guessing on the application's
behalf, and an application that knows the difference says so by catching its own
exception.

Backoff is exponential and capped: 5s, 10s, 20s, up to ten minutes. The cap
matters as much as the growth — without one, the fifteenth attempt is nine hours
out, which for an invoice reminder is indistinguishable from never.

`queue.backoff.jitter` is worth raising above zero for anything that talks to a
shared service: a hundred jobs that failed together otherwise retry together and
knock the recovering service over again.

**Retrying a failed job resets its attempts.** Somebody has looked and decided
the reason is gone; a retry that immediately exhausted the attempts it had
already spent would answer a question nobody asked.

## What a crashed worker costs

**One retry, not one job.** A reservation expires: once `reservedUntil` has
passed the job is available again, so a worker killed mid-job leaves work that
comes back on its own.

That is also why there is no signal handling here. `pcntl` does not exist on
Windows, a guarded call to it would be code nobody in this project can run, and
correctness does not need it.

The attempt count is incremented when a job is **reserved**, not when it
finishes, so a job that kills whatever picks it up still runs out of tries
instead of cycling for ever. Counting on success is the obvious way round and
the wrong one.

**Timeout is a reservation, not an interruption.** Stopping a job in the middle
of a socket read needs `pcntl_alarm`, which is not portable here. What the
timeout does is bound how long a job may hold its claim, so a hung process
delays work rather than stopping it. `set_time_limit()` is asked as well, which
covers the runaway-loop case where it is supported. Between them, that is what
this framework can honestly enforce — and saying so beats a `timeout` setting
that quietly does nothing.

## How the database store claims a job safely

The hard part of a database queue is claiming a job so that two workers never
get the same one.

Inside a transaction, the next due job is read with the query builder's
`lockForUpdate()` — `FOR UPDATE` on MySQL and PostgreSQL, `UPDLOCK` on SQL
Server — and then claimed by an `UPDATE` whose condition repeats "not reserved,
or its reservation has lapsed".

The lock makes a second worker wait; the repeated condition makes the claim safe
even where a lock would let both through, because only one `UPDATE` can change
the row from free to reserved. A worker whose claim changed nothing gets no job
and asks again.

Waiting, reserved and failed jobs share one table, told apart by `failed_at`. A
reservation lapses as it does in the file store, so a killed worker's job comes
back with its attempts counted.

The table is created by `php laika migrate` while `QUEUE_STORE=database`:
`queue.table` (`jobs`) on `queue.connection` (the default connection). The
conformance suite runs against it on MySQL, PostgreSQL, SQLite and SQL Server.
Unlike the file store, it serves **any number of machines** sharing the
database.

## How the file store claims a job safely

The whole design is one system call. Moving a job from `pending/` to `reserved/`
is a `rename()`, which either succeeds or fails as a single operation on NTFS
and on every POSIX filesystem — so the loser of a race gets `false` rather than
a second copy of the job. No lock files, and nothing to leak when a worker is
killed.

```
system/Queue/
  <queue>/pending/<due>-<id>.job     waiting; the name sorts by due time
  <queue>/reserved/<id>.job          claimed, with its reservation inside
  failed/<id>.job                    gave up; kept until somebody looks
```

The due time is in the filename, so a folder listing is already in the order a
worker wants. Ten thousand pending jobs is fine; ten million is where this
should be the database store.

**One machine, any number of workers.** Two machines sharing this over NFS would
be trusting a network filesystem's rename semantics, which is a bet worth not
making.

## Watching jobs

`job.queued`, `job.started`, `job.finished` and `job.failed` — the last carrying
the exception, the envelope, and whether it will be retried.

That is how logging hears about a failed job without the queue knowing a logger
exists, and how an application adds metrics without touching the worker. **The
same hooks fire under sync**, so nothing an application observes changes when a
queue is switched on.

The queue layer itself cannot see `Request` or `Response` at all, and a test
enforces it: a worker runs where no browser is waiting.

**Queued payloads are not web-readable.** A job file is a serialised object that
something later unserialises, so a folder anybody could write to is a folder that
could hand a worker an object of its choosing. `system/` is outside `public/`,
and an architecture test keeps it there.

**And with `APP_KEY` set, payloads are sealed.** Each one carries an HMAC made
with a key derived from `APP_KEY`, and a worker checks it before unserialising:
a job written into the store by anybody else — through a shared database, a
directory with loose permissions — fails without being unserialised. A job
queued before the key was set, or under a key since changed, fails the same way,
so drain the queue before setting or rotating `APP_KEY`.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Jobs are never processed | No `queue:work` is running for that queue | Start one; check `QUEUE_STORE` is not `sync` |
| A worker on another machine sees nothing | `file` keeps jobs on one machine | `QUEUE_STORE=database`, then `php laika migrate` |
| A job runs twice | A worker died, or the job outlived `QUEUE_TIMEOUT` | Make jobs safe to repeat; raise the timeout |
| A job sees stale data | The identity map holds rows for the life of the process | `ModelManager::flush()` at the start of the job |
| An exception breaks the request | The store is `sync`, so the job ran inline | That is correct; set a real store |
| A job is dispatched and refused | The class has no `handle()` | Add one; the message names the line |
| Failed jobs pile up | Nothing retries them automatically | `php laika queue:failed --retry-all` after fixing the cause |
