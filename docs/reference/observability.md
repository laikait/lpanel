# Observability

Observability is being able to answer "what happened on that request" after it
has gone. This framework gives you three things:

1. **An id on every response and every log record**, always on.
2. **A profiler** that says where a request's time went, off unless asked for.
3. **A slow-query warning**, off unless a threshold is set.

There is no dashboard, no page and no store. What is observed leaves through two
channels every deployment already has: **the log**, and **response headers**. An
architecture test holds `engine/Observability/` to that — nothing in it opens a
file, prints, or sets a header on its own.

## Always on: which unit of work is this?

Every request, console command and job runs inside a **trace**. A trace has an
id of its own, and a **correlation id** naming the thing that started the chain:

```
POST /invoices/run          request   id 6aa97a..e1   correlation 6aa97a..e1
  └ queues InvoiceRun
      job (a worker, later)  job       id 6aa97b..07   correlation 6aa97a..e1
        └ queues SendInvoice
            job              job       id 6aa97b..3c   correlation 6aa97a..e1
```

The correlation travels inside the queued job's envelope, so *"what did that
click cause"* is one search of the log, across the queue boundary where a
request id alone stops. Nobody passes an id along by hand.

What that buys you, with nothing configured:

- **Every response carries `X-Request-Id`** — assets, 404s and error pages
  included. It is what a user quotes to support.
- **Every log record carries `trace`, `request_id` and `correlation_id`.** The
  log manager adds them underneath the record's own context, so a worker logging
  about some other job can still say which one it means.
- **Error records say what the request was** — method and path — beside the id
  the client was given. Not the query string and not the body: that is where a
  token is, and an error log is read by more people than the request was.

Reading them in your own code:

```php
public function __construct(private readonly Tracer $tracer) {}

$this->tracer->current()->id;                 // this request, command or job
$this->tracer->current()->correlationId;      // the chain it belongs to
$this->tracer->current()->elapsedMilliseconds();
$this->tracer->current()->memoryGrowth();     // bytes, since the unit of work began
```

Ids are 24 hex characters, sortable by time.

**An id from outside is ignored** unless `observability.trust_incoming_ids` is
on. That is right behind a gateway that stamps `X-Request-Id` and
`X-Correlation-Id`, so its log and this one agree — and wrong otherwise, because
a client should not choose the id its own requests are logged under. A trusted
id is still checked against a pattern before it is used, because a newline in it
would let a client write a log line of its own.

## Off unless asked for: where did the time go?

```bash
APP_PROFILE=true
```

The **profiler** times every hook listener, every filter listener, every module
stage — discover, resolve and register as totals; load and boot per module,
because those run a module's own code — and every database statement.

At the end of each request, command and job it writes one record to the
`profile` log channel:

```
INFO [profile] GET /customers.json 200 in 17.53 ms {"request_id":"6aa979e8...",
  "elapsed_ms":17.547,"memory_growth_kb":1284.6,"memory_peak_mb":6,
  "categories":{"module":{"count":9,"ms":10.523},"filter":{"count":5,"ms":0.277},...},
  "slowest":{"module":[{"name":"register","detail":null,"ms":4.397},
                       {"name":"boot","detail":"Example","ms":1.72},...],
             "hook":[{"name":"request.received","detail":"engine App\\Engine\\Security\\Guard::onRequest",...}]}}
```

With `APP_DEBUG` on as well, the same numbers go out as a `Server-Timing`
header, which a browser's developer tools already draw as a waterfall:

```
Server-Timing: app;dur=19.03, module;dur=10.52;desc="9", filter;dur=0.28;desc="5", hook;dur=0.03;desc="5"
```

Only in debug, because it is an exact description of where a request spends its
time — not something to hand every client of a production system. The log gets
it either way.

Four things worth knowing about the numbers:

- **They are aggregated, not a list of events.** A page fires thousands of filter
  listeners; each measurement is folded into a count, a total and a maximum under
  its name, and the number of names kept per category is capped, so a worker
  running for hours holds the same amount. The count is also the answer to N+1:
  *the same statement fifty times* is one line with `"count":50`.
- **They are inclusive.** A hook listener that applies a filter counts the
  filter's time as its own, and the filter counts it too. Categories answer "how
  long was spent inside hooks" and "inside queries" separately; they do not add
  up to the request.
- **Queries are named by their SQL, never their values.** The connection's
  observation seam does not pass the bindings at all — a test checks the observer
  receives exactly three arguments — because a bound value is where a password or
  a card number is, and a profile is exactly the output that gets pasted into a
  ticket.
- **Listeners are named for what they are**: `Guard::onRequest` for a method,
  `closure at Bootstrap.php:577` for a closure.

Your own code can time its own work under its own names:

```php
$profiler->measure('billing', 'invoice run', fn () => $run->execute());
```

With profiling off, that is a function call and nothing else.

## Slow queries, profiling or not

```bash
SLOW_QUERY_MS=250
```

Any statement slower than this is a warning on the `database` channel, with its
SQL, its duration, how many values were bound, and how many rows it returned or
changed — and never the values themselves.

It is the one timing worth paying for on every statement in production: a query
that took four seconds is a fact nobody should have to reproduce to learn about.

## Settings

| Key | Default | What it does |
|---|---|---|
| `observability.profile` | `false` (`APP_PROFILE`) | Attach the profiler. Leave it off; switch it on to find out where a slow page goes. |
| `observability.slow_query_ms` | `0` (`SLOW_QUERY_MS`) | Warn about statements slower than this. `0` is off. |
| `observability.trust_incoming_ids` | `false` | Use `X-Request-Id` / `X-Correlation-Id` from the request. Only behind something that sets them. |

`php laika about` says which of these is on.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| No profile records | Profiling is off, or no log writer is configured | `APP_PROFILE=true`, and see [Logging](logging.md) |
| No `Server-Timing` header | It is debug-only | The same numbers are in the log |
| Log records with no request id | Something logged outside a trace | Rare; check what started it |
| A gateway's id is ignored | Incoming ids are not trusted by default | `observability.trust_incoming_ids`, behind that gateway only |
| Slow queries are not reported | `SLOW_QUERY_MS` is 0 | Set a threshold |

## Why it works this way

### How "off" costs nothing

The subsystems being measured do not know the profiler exists. `HookEngine`,
`FilterEngine`, `ModuleManager` and `Connection` each expose one `observe()`
seam — a closure told what ran and for how long — and the profiler is the only
thing that attaches to them, from the bootstrap.

With profiling off, nothing is attached, so a hook firing pays for a null check
rather than a clock. The benchmark suite shows it: a hook with ten listeners
costs 2.0 µs unobserved, as it did before the seam existed, and 15.6 µs
profiled. That difference is why it is off by default.

Two architecture tests keep this true: **no subsystem references the profiler**
(add a check for "is profiling on" inside `HookEngine` and the build fails), and
**every `observe()` seam is connected**, so a subsystem cannot quietly drop out
of every profile.

### Why there is no dashboard

The instruction that shaped all of this is the specification's: *"Do not build a
giant debug dashboard initially. First build reliable instrumentation APIs."*

A dashboard is a store, a UI, an access-control question and a thing to keep
running. The log and a response header are already deployed, already collected,
and already the place an operator looks.
