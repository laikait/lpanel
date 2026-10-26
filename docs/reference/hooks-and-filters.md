# Hooks and filters

Hooks and filters are how one module changes what another one does, without
either editing the other's code.

- A **hook** announces that something happened. Return values are ignored.
- A **filter** transforms a value. The callback must return one.

```php
add_hook('customer.created', $callback, priority: 20);
do_hook('customer.created', $customer);

$total = apply_filter('invoice.total', $total, $invoice);
```

To use them in a real module, follow
[Extending other modules](../guides/extending-other-modules.md). This page is
the details, plus the full list of what the framework fires.

## Listening

| Function | Does |
|---|---|
| `add_hook($name, $callback, $priority = 10)` | listen |
| `do_hook($name, ...$args)` | announce |
| `remove_hook($name, $callback)` · `has_hook($name)` | stop listening, check |
| `add_filter($name, $callback, $priority = 10)` | transform |
| `apply_filter($name, $value, ...$args)` | run the chain |
| `remove_filter($name, $callback)` · `has_filter($name)` | stop, check |

**A lower priority runs first.** Equal priorities fire in registration order,
and registration order is fixed by module order, so the order is total, with no
ties to guess at.

### Array callbacks are static calls

`[Class::class, 'method']` is a static call in PHP, here as everywhere. To listen
with an instance method, register from `onBoot`, where the instance can be
injected:

```php
$module->onBoot(static function (Auditor $auditor, HookEngine $hooks): void {
    $hooks->add('customer.created', [$auditor, 'record'], 10, 'Audit');
});
```

Registering a non-static method as `[Class::class, 'method']` is refused at
registration time, with a message pointing here — rather than fatalling weeks
later when the hook first fires.

## Three behaviours that differ from WordPress

1. **A listener added while a hook is running does not join that run.** The list
   is taken once, at the start. Determinism beats the trick.
2. **Recursion is capped at 64 levels**, so two modules filtering each other
   produce a readable error instead of a stack overflow.
3. **In debug mode, a filter that returns `null` for a non-null value throws**,
   naming the callback. That is almost always a missing `return`. In production
   the value is used as it is.

## Helpers are not facades

The framework bans facades and ships ten global functions. Those are only
contradictory if "reachable globally" and "facade" mean the same thing.

A facade is a class with `__callStatic` that resolves **arbitrary** services out
of a global container: call sites no static analyser can type, and one class per
service. What exists here is a **closed set**, each with a concrete typed
signature:

```
add_hook    do_hook      remove_hook    has_hook
add_filter  apply_filter remove_filter  has_filter
asset       template
```

`asset()` and `template()` return their manager rather than doing the work,
because each of those APIs is several verbs, and more global functions would be
worse than one. The return type is a single concrete class either way, so the
call site stays exactly as analysable as an injected one.

**The set is complete.** What decides membership is a rule, not a number: a
subsystem is here when the specification says authors reach it globally, because
those are the places with no constructor to inject into. Three architecture
tests keep that honest:

1. `Support\Extensions` exposes exactly `hooks`, `filters`, `assets` and
   `templates`, and the test asserts the **names**.
2. No file under `engine/` may call a global helper, except `helpers.php`.
   Engine code takes its collaborators by constructor injection.
3. Nothing in `Extensions` may take a string. A lookup by name is a service
   locator, whatever the class is called.

Use the helpers in `module.php` files, in templates, and in one-off extension
code — the places with no constructor. Module *classes* should prefer injection,
as the showcase modules in `tests/Fixtures/Showcase/` do.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A listener never runs | The name is wrong, or your module registered after the point fired | Check the name in the table below |
| "…is not static, so […::class, '…'] cannot be used" | An instance method registered as an array callback | Register from `onBoot` with the object |
| A filter throws about `null`, in debug only | The listener is missing its `return` | The message names the listener |
| Two modules' listeners run in the wrong order | Same priority, so module order decides | Give one an explicit priority |
| "recursion" in a message | Two filters call each other | Break the cycle; the cap stopped a crash |

## Lifecycle extension points

Hooks are named `<subject>.<what-happened>`; filters are named after the value
they carry.

| Hooks | Filters |
|---|---|
| `app.booted`, `app.ready` | `request.instance` |
| `module.registered`, `module.booted` | `router.path` |
| `request.received` | `route.match` |
| `route.matched` | `dispatch.handler` |
| `dispatch.before`, `dispatch.after` | `dispatch.parameters` |
| `request.failed` | `dispatch.result` |
| `response.sent` | `response.instance` |
| `app.terminating` | `error.response` |
| `command.matched` | `asset.response` (engine listeners only — see [Assets](assets.md#an-asset-request-loads-no-module)) |
| `command.finished` | `dispatch.response` |
| `command.failed` |  |
| `error.reported` | `mail.allowed` |
| `job.queued`, `job.started` |  |
| `job.finished`, `job.failed` |  |
| `schedule.started` |  |
| `schedule.finished`, `schedule.failed` |  |
| `session.started` | `system.command.max_output` (narrow only) |
| `session.regenerated` |  |
| `auth.identified`, `auth.login` |  |
| `auth.logout`, `auth.failed`, `auth.throttled` |  |
| `auth.rehash` | `authorization.decision` |
| `auth.password_reset_requested`, `auth.password_reset` |  |
| `auth.email_verified` |  |
| `auth.social_user` | `social.account` (an `Account`, or `false` for nobody) |
| `auth.two_factor_enabled`, `auth.two_factor_disabled` |  |
| `auth.two_factor_failed`, `auth.recovery_code_used` | `feature.active` |
| `auth.two_factor_throttled` |  |
| `system.audit` | `system.command.timeout` (narrow only) |
| `mcp.request.received`, `mcp.request.failed` | `mcp.tool.input` (before validation) |
| `mcp.response.created`, `mcp.access.denied` | `mcp.tool.output` |
| `mcp.tool.before`, `mcp.tool.after`, `mcp.tool.failed` | `mcp.tool.description` |
| `mcp.resource.read`, `mcp.resource.failed` | `mcp.resource.output` |
| `mcp.prompt.loaded`, `mcp.prompt.failed` | `mcp.prompt.output` |
| `database.query.failed` |  |
| `database.transaction.committed`, `database.transaction.rolled_back` |  |
| `database.transaction.retrying` |  |
| `http.client.sent` | `http.client.request` |
| `mail.sent` | `mail.message` |

The `database.*` hooks report what has already happened. A listener cannot undo
a commit or replace a database failure, and nothing it throws causes a
transaction to be retried. Their arguments are in
[Database](database.md#watching-statements-and-transactions).

The `mcp.*` extension points never run ahead of MCP's security checks. A
capability the caller may not use reaches no listener; `mcp.tool.input` runs
before the arguments are validated against the tool's schema; and an output
filter must return the same kind of value it was given. A listener can refuse by
throwing an MCP exception, but no listener can grant access.
