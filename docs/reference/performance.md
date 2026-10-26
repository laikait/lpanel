# Performance

What this framework costs, measured rather than asserted — and what to do first
if your application is slow.

## Do these first

1. **Turn opcache on.** Without it a request costs 45–60 ms, nearly all of it
   PHP compiling around 170 files, and nothing else on this page is visible
   behind that. XAMPP ships with it off.
2. **Run `php laika cache:warm`** in production. It replaces reading `config/`
   and scanning module folders with one opcache-held file each.
3. **Read fewer columns.** A list page that builds full domain models to show
   three columns is the most common slow page. Use `into()` or `rows()` — see
   [Repositories and queries](data.md).
4. **Look before guessing.** `APP_PROFILE=true` writes where each request's time
   went; `SLOW_QUERY_MS=250` reports the statements worth knowing about. See
   [Observability](observability.md).

## The numbers

From `composer bench`, with opcache on. This machine is Windows with a
thread-safe PHP, where a filesystem stat costs tens of microseconds — so treat
the scan figures as pessimistic, and the relationships as the point:

| Measured | Cost |
|---|---|
| bootstrap + boot every module | 1.7 ms |
| bootstrap + serve an asset | **1.65 ms**, against 2.13 ms booting every module first |
| read configuration from `config/` → from the cache | 249 µs → **13 µs** |
| scan module folders → read the discovery cache | 487 µs → **4.4 µs** |
| compile a 500-route table | 0.87 ms, once per process, lazily |
| match a route among 500 | 0.8 µs static, 2.8 µs with a constrained parameter |
| resolve 50 modules' dependencies | 141 µs |
| insert 100 rows one at a time → `insertMany()` (SQLite) | 1.00 ms → **0.35 ms** |
| hydrate 50 rows into domain models → into read models (SQLite) | 411 µs → **168 µs** |
| fire a hook with 10 listeners / apply a filter with 10 | 2.0 µs / 2.6 µs |

## What measuring changed

The specification's instruction is that performance "must not be based only on
theoretical architecture", so this work started by measuring the framework as it
stood, and changed only what the measurements pointed at:

| Found | Done about it |
|---|---|
| An asset request booted every module — loaded every `module.php`, registered every route, ran every `onBoot` | It now stops after discovery. [An asset request loads no module](assets.md#an-asset-request-loads-no-module) |
| The listener that tells authentication about each request **built** the auth manager to do it — and with it the user provider, the shared repository and its models, on every request including a stylesheet | It records the request and hands it over when a manager exists or is built. A public page constructs nothing |
| Reading configuration and scanning module folders were the largest remaining costs of a boot | `cache:warm` replaces both with one opcache-held file each. [The production boot path](../operations/deployment.md#the-production-boot-path) |
| Registration asked the filesystem, per module, per request, whether `assets/` and `Templates/` exist | Discovery asks once and records the answer; the cache carries it; an architecture test stops anything after discovery from asking again |

Most of what a heavy backend needs was already there: lazy connections, a lazily
compiled route trie, memoised reflection, read models.

## What is deliberately not cached

The specification lists routes and dependency resolution among the things to
cache. Measuring says not yet, and the reasons are in the numbers:

- **Routes.** A route is declared inside `module.php`, next to the hooks and
  services that must be registered on every boot regardless, so the declaration
  runs anyway. What a cache could skip is compiling the table — 0.87 ms for 500
  routes, once per process, never for a request that does not route — at the
  price of an invalidation story, and of refusing closure handlers, which no
  cache file can hold.
- **Dependency resolution.** 141 µs for fifty modules, and it depends on what
  every `module.php` declares: a cached graph is wrong the first time somebody
  edits one.

Both are benchmarked, so the day either stops being true is a number rather than
a feeling, and `cache:warm` says out loud that it does not cache them.

## Reading a lot of rows

Each mechanism the specification asks for is a method:

| Asked for | Where it is |
|---|---|
| Column selection | `select()`, and `into()` / `pageInto()`, which read only the columns a read model declares |
| Pagination | `page()` / `pageInto()` — a count ignoring limit and offset, then the slice |
| Chunking | `chunk($size, $callback)` |
| Streaming | `stream()`; over SQL, `fetch()` is a cursor, so one row is held at a time |
| Read models | `ReadModel`, built by `into()` without hydration or identity mapping |
| Explicit relations | `RelationManager` — declared, never lazy; loaded in a second query and linked |
| Batch queries | `whereIn()`, which is what relation linking runs on — N+1 cannot happen by accident |
| Bulk operations | `insertMany()`, `updateWhere()`, `deleteWhere()` — [Bulk writes](data.md#bulk-writes) |

The things it says to avoid are avoided by shape rather than by discipline:
there is no lazy association to fire a query from a template, no automatic
relationship loading, and the cheap reads are listed first.

**Transactions** were already what the specification describes:
`Connection::transaction()`, with the application choosing the boundary, nested
calls becoming savepoints, and no repository method opening one of its own. An
invoice, its lines, its accounting entries and its payment are one transaction
because the calling code says so.

## Benchmarks, and what "track regressions" can honestly mean

```bash
composer bench                                      # every subject in the specification, section 50
composer bench -- --filter="Route resolution"
composer bench -- --save=system/Runtime/bench/before.json
composer bench -- --compare=system/Runtime/bench/before.json --threshold=20 --fail
```

A timing is a fact about the machine that produced it. Comparing a laptop with a
CI runner is noise, so is comparing opcache on with opcache off, and a
comparison across either is **refused** rather than reported. The useful
comparison is one machine before and after a change, which is what `--save` and
`--compare` are.

What must not regress on *any* machine is asserted exactly, in the test suite,
where it fails the build: an asset request running no module code, a public page
constructing no authentication layer, a warmed boot reading its caches, nothing
after discovery probing a module folder, and every one of the specification's
eleven subjects having a benchmark.

CI also runs the benchmarks on one PHP version and prints them —
informationally, because a shared runner's timings move by a third between runs,
and a threshold there would fail builds at random.

Benchmarks live in `tests/Benchmark/`, which ships with nothing: the web server
denies `tests/`, and a production install has no dev dependencies.

## Development and production

| | Development (`APP_DEBUG=true`) | Production (`APP_DEBUG=false` + `cache:warm`) |
|---|---|---|
| Errors | detailed | safe |
| Module discovery | scanned, always — a debug process never reads the cache | one cached file |
| Configuration | read from `config/` unless a cache exists | one cached file, checked against the environment |
| Asset version tokens | recomputed | cached (with `CACHE_STORE=file`, across requests) |
| Asset delivery | PHP | the web server for `assets/`, PHP for module assets, or a CDN via `assets.url` |
| Routes, dependencies | compiled / resolved per process | the same — see above |
