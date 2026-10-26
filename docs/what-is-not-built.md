# What is not built

Things the framework does **not** have, each with the reason. The point of this
page is that you do not spend an afternoon looking for a feature that was left
out on purpose — or build one without reading why it was left out.

The list has two parts:

- **Not built yet**: reasonable to add later. Each row says what would have to be
  true first.
- **Not going to be built**: a decision, not a gap. Adding it would work against
  how the rest of the framework is put together.

Then a short list of the places where the framework deliberately differs from its
own specification.

## Not built yet

| Not built | Why, and what it would take |
|---|---|
| **An example application** (Phase 29 of the specification: `Customer`, `Billing`, `DemoGateway`) | Postponed by the project owner. `tests/Fixtures/Showcase/` does nearly all of it, through every part of the framework, but it is a test fixture rather than something you can install — and it has no second module *depending on* the first, which is the part that would show module dependencies working. |
| **A log writer that sends records to another server** | Possible now that there is an [HTTP client](reference/http-client.md), but none ships: which service, which format and what to do while it is down are the application's choices. `LogWriter` is three methods. |
| **Cache stores for APCu, Redis and Memcached; a Redis queue store** | None of those extensions was installed where the framework was written, so every line would be untested. The hard parts — clearing one namespace, taking a job so that no other worker takes it — cannot be written blind. Each kind of store has a conformance test suite, which is what would make adding one safe. |
| **The asset and template extension points the specification lists as future** (§42: `asset.registered`, `asset.resolved`, `asset.served`; filters `asset.url`, `asset.version`, `asset.mime`, `asset.cache_control`, `template.path`) | Nothing needs them yet, and an asset request loads no module at all, so a module could not listen to most of them. Two from that list do exist, under different names: `module.loaded` as `module.registered` / `module.booted`, and `api.response` as `dispatch.response`. |
| **CSRF tokens tied to one session** | Today a token is tied to a browser, and changes when the session id changes, including at login. Tying tokens to a session means changing how they are signed and checked, and that has not been done. |
| **Byte ranges for assets** (resuming a partial download) | Responses say `Accept-Ranges: none`. Serving large video or audio is a job for the web server or a CDN, not for PHP. |
| **MCP beyond one request and one response**: event streams, `Mcp-Session-Id` sessions, `list_changed` and progress notifications, cancellation, resource subscriptions, completions, sampling and elicitation | Each of these needs either a connection that outlives a request or a server that speaks first. Neither fits a PHP process that answers and then ends, and no module has needed them. See [MCP](reference/mcp.md#http). |
| **A prepared list of MCP capabilities for production** | Measured, then declined: capabilities are declared in `module.php`, and registering a hundred takes about 0.15 ms. See [MCP](reference/mcp.md#in-production). |
| **Caching the routes and the module dependency order** (§38) | Measured, then declined: 0.87 ms to build 500 routes once per process, and 141 µs to work out the order of 50 modules. See [What is deliberately not cached](reference/performance.md#what-is-deliberately-not-cached). |

## Not going to be built

| Not built | Why |
|---|---|
| Facades, service providers, middleware, gates and policies, form requests, route-model binding, copies of Eloquent, Blade or Artisan, `make:*` generators | Specification §54 rules them out, and an architecture test enforces each one. The framework's own answers are modules, hooks and filters, and plain classes. |
| A debug dashboard | §52: measure first. What the framework observes goes to the log and to response headers only, and a test keeps it that way. |
| A route parameter that matches everything (`{path:.*}`) | It would make route matching ambiguous, and nothing has needed it. |
| `OR` and joins in `Query` | `Query` must work the same on every kind of storage, memory included, and a join cannot. Both belong in a repository method written over SQL, where the query builder offers them — see [The query builder](reference/database.md#the-query-builder). |
| `ModelQuery` (the §14 list) | It would be a query object with nothing to query. `Data\Query` is the query API the specification asks for in §21. |
| PSR-3's `LoggerInterface` on `Logger` | `log()` takes a `Level` enum instead of a string, which is worth more than the shared interface. A twelve-line adapter is in [Logging](reference/logging.md). |
| RFC 9457 `problem+json` error bodies | See [One error shape](reference/rest.md#one-error-shape). |
| A Content-Security-Policy or HSTS switched on by default | A general-purpose CSP is either too loose to help or breaks the first page you write, and HSTS cannot be undone once browsers have seen it. `php laika security:check` reports both. |
| Schedules more often than once a minute; handling signals in the worker; a PHP process that stays resident to serve HTTP | There is no resident process, and Windows has no `pcntl`. Workers that stop on purpose, and jobs that become available again after a timeout, give the same reliability. |
| HTTP Basic authentication | It makes the browser show a login box that your application cannot style, cancel or explain. |
| A warning at runtime when you use a deprecated API | See [API stability](contributing/releases.md). |

## Where this differs from the specification

- **Twig is required, and is the default template engine.** §25 and invariant 14
  say Twig must stay optional. The project owner decided otherwise, so the pages
  that ship can be Twig. What still holds: the template manager talks only to
  `TemplateEngine`, nothing outside `TwigTemplateEngine` mentions Twig, and PHP
  templates render through the same manager.
- **The framework is not committed phase by phase** (§56 step 16). Committing is
  the project owner's step.
