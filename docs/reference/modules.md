# Modules

A *module* is a folder under `modules/` that adds a capability to the
application: pages, commands, jobs, services, or anything else. It declares
itself in one file, `module.php`.

```
modules/
  Shared/       the one shared module: always there, always first
  Billing/      any other folder with a module.php is a module
  Stripe/
```

**The folder name is the module's id**, and you choose it: `Billing`, `Crm`,
`Stripe_Gateway`. It starts with a capital letter and holds only letters,
digits and underscores, because it is also the PHP namespace segment
(`App\Modules\Billing`). The id names the module everywhere else:

| Where | For `modules/Billing/` |
|---|---|
| dependencies | `$module->requires('Billing')` |
| templates | `@Billing/invoice` |
| translations | `'Billing.invoice_created'` |
| assets | `/assets/module/Billing/…`, `asset()->module('Billing', …)` |
| configuration | `config/Billing.php` |

`Shared` is the only fixed name. Every application has it; it registers before
every other module and may depend on none of them. Put in it only what more
than one module really needs.

Modules outside `modules/` are added through `modules.paths`, a list of places
to look. An entry is either a folder of modules or, if it has a `module.php` of
its own, a single module:

```php
// config/modules.php
return ['paths' => ['modules', 'vendor/acme/crm-module']];
```

A folder name that cannot be a module name is refused at boot, and so is the
same name in two of those places.

**There is nothing to extend and nothing to implement.** `ModuleContext` is the
whole API a module author learns.

## Start a new module

There is no `module:make` command, on purpose (see
[Console](console.md)). A module is one file, so copy this one. For a module
called `Shop`:

```
modules/Shop/
  module.php        the only required file
  Http/             handlers        App\Modules\Shop\Http\…
  Model/            models          App\Modules\Shop\Model\…
  Data/             repositories    App\Modules\Shop\Data\…
  Templates/        @Shop/…
```

Only `module.php` is required. The other folders are a convention, not a rule:
any folder under `modules/Shop/` maps to `App\Modules\Shop\…`.

```php
<?php // modules/Shop/module.php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;

return static function (ModuleContext $module): void {
    $module
        ->name('Shop')
        ->version('0.1.0')
        ->description('Products and orders.');

    $module->requires('Shared', '^0.1');

    $module->routes(static function (RouteCollector $routes): void {
        // $routes->get('/products', [ProductPages::class, 'index'])->name('shop.products');
    });
};
```

Then check it was found:

```bash
php laika module:list     # Shop is listed
php laika cache:clear     # only if the module cache is on (APP_DEBUG=false)
```

## A module declares itself

```php
<?php // modules/Customer/module.php

return static function (ModuleContext $module): void {
    $module->name('Customers')->version('1.0.0');

    $module->config(['page_size' => 25]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(CustomerCatalog::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/customers', ListCustomers::class)->name('customers.index');

        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
                ->where('id', '\d+')
                ->name('customers.show');
        }, name: 'api.v1.', meta: ['api' => true]);
    });

    $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
    $module->filter('customer.name', [CustomerFilters::class, 'normalise']);

    $module->onBoot(static function (CustomerCatalog $catalog): void {
        // Every module has registered by now. Dependencies are injected.
    });
};
```

| Declaration | Adds |
|---|---|
| `name()` · `version()` · `description()` | what `module:list` shows |
| `config([...])` | **defaults**; `config/` overrides them |
| `services(…)` | what the container can build |
| `routes(…)` | pages and endpoints |
| `commands(…)` | `php laika …` commands |
| `schedules(…)` | tasks on a timetable |
| `access(…)` | capabilities and roles |
| `mcp(…)` | tools, resources and prompts |
| `templates(…)` | filters and functions every template can call ([Templates](templates.md#filters-and-functions-a-module-offers)) |
| `hook()` · `filter()` | listeners on extension points |
| `requires()` · `optionally()` | other modules this one needs |
| `onBoot(…)` | code to run once everything has registered |

**Everything above is recorded, not executed.** When the closure returns,
nothing has been bound, routed or hooked. That is what makes the order
deterministic rather than dependent on the order the filesystem returned
folders.

## The lifecycle

| Stage | What happens | What is illegal |
|---|---|---|
| **Discover** | The configured roots are scanned for `module.php` — or the discovery cache is read. No module code runs. **A request for `/assets/...` stops here.** | — |
| **Load** | Each closure runs and records its declarations. | Resolving services, firing hooks, I/O |
| **Resolve** | Dependencies are checked across every module at once, and the registration order is fixed. Disabled modules were already skipped at Load. | — (no module code runs) |
| **Register** | Declarations are replayed across all modules **by category**: config → services → routes → template helpers → commands → schedules → access → MCP → hooks → filters. | Reading from the container (impossible) |
| **Boot** | `onBoot` callbacks run in module order, with dependencies injected. | Declaring anything new |
| **Ready** | `app.booted`, then `app.ready`. | — |

**Replaying by category rather than module by module** is the important detail:
all config is merged before any service factory is defined, and every service is
bound before any route is registered. That removes the ordering bugs service
providers are known for.

Module order is `Shared` first, then every other module by folder name —
adjusted only where a dependency forces it. Never filesystem order. **`Shared`
always registers first**, which is what makes it genuinely shared.

## Depending on another module

```php
return static function (ModuleContext $module): void {
    $module->name('Payment')->version('1.3.0');

    $module->requires('Billing', '^1.2')
           ->optionally('Crm', '^2.0');
};
```

Modules are named **by id**, which is the folder name: `Billing` for
`modules/Billing/`.

Declaring it in the module's own file means installing a module brings its
requirements with it, and a reviewer sees them beside the routes that rely on
them.

**Four refusals, all at boot**, each naming both modules:

| Refusal | Means |
|---|---|
| **missing** | required and not installed — with a "did you mean" when it is plausibly a typo |
| **disabled** | installed, but listed in `modules.disabled`. A different fix from "missing", so a different message |
| **version conflict** | installed, and its `version()` does not fit the constraint |
| **circular** | no order exists; the circle is printed: `A -> B -> A` |

A web client sees a generic 500; an operator at the console sees the whole
message, because the framework wrote every word of it. A dependency problem
found at runtime is found by whichever request first touches the missing piece.
Found at boot, it is found by whoever deployed.

### Optional means "works without it", not "any version will do"

An optional dependency that is present and enabled is held to its constraint,
and orders registration exactly like a required one. Only its **absence** is
forgiven.

The showcase's `ExampleGateway` uses one honestly: it listens to
`customer.created`, which only `Example` fires. Without `Example` the listener
is never called — but an `Example` 1.0 that changed the event's payload should
stop the application rather than surprise the listener.

To act on whether an optional partner is there, listen for its hooks: they
simply never fire without it, and need no check. When that is not enough, inject
`ModuleRegistry` in an `onBoot` callback and ask `isEnabled()`.

### The order changes only where a dependency forces it

Resolution is a stable topological sort: at each step it takes, of the modules
whose dependencies are all placed, the one that came first in the default
order (`Shared`, then by name).

An application that declares nothing registers exactly as before, and one
declaration moves exactly one module — the one that has to wait. Within each
registration category a module is registered after everything it requires, which
is visible from inside a module: two listeners at the same priority run in that
order.

**`Shared` first is never broken**, and that is a fifth refusal rather than a
hope. `Shared` depending on a module would either move that module ahead of it
— breaking "every module may rely on `Shared` without saying so" for modules
that never mentioned it — or be unsatisfiable. Refusing it means the sort only
ever moves the other modules among themselves, so `Shared` registers first by
construction.

## Versions and constraints

`version()` is **exactly `MAJOR.MINOR.PATCH`**, checked where it is written. No
`v` prefix and no pre-release suffix: their ordering rules are the part of
semver everybody gets subtly wrong, and a module under development is `0.x`,
which the caret already treats as unstable.

Constraints are **a subset of Composer's syntax that means exactly what Composer
means**. The rest is refused:

| Constraint | Matches |
|---|---|
| `*` | any version |
| `1.2.3` | exactly that |
| `^1.2` / `^0.3` | `>=1.2.0 <2.0.0` / `>=0.3.0 <0.4.0` |
| `~1.2` / `~1.2.3` | `>=1.2.0 <2.0.0` / `>=1.2.3 <1.3.0` |
| `>=1.2 <2.0` | both (space or comma) |
| `^1.0 \|\| ^2.0` | either |

**A bare partial version like `1.2` is refused**, with both spellings offered.
Composer reads it as exactly `1.2.0`; the person who typed it almost always
meant "1.2-ish"; and the disagreement stays invisible until `1.2.1` is installed
and the application will not boot.

Why modules need this when Composer exists: modules under `modules/` are **not**
Composer packages. They are folders in one repository, and nothing else is going
to check that `Payment` still fits the `Billing` beside it.

## Disabling a module

```php
// config/modules.php
return ['disabled' => ['Stripe']];
```

A disabled module is **installed but never runs**: not its `module.php`, not its
boot callbacks, not its listeners, and its assets are not published. It stays
known to the registry so "disabled" and "missing" can be told apart, and
`module:list` shows it beneath the table:

```
  ID              KIND    NAME             VERSION  ...  REQUIRES
  Shared          shared  Shared           0.1.0    ...  -
  ExampleGateway  module  Example Gateway  0.1.0    ...  Example? ^0.1 (absent)

Disabled (installed, switched off in modules.disabled): Example
```

Anything that *requires* it refuses to boot; anything that uses it *optionally*
carries on without it.

Two refusals of its own: an id that is not installed, because a typo would leave
the module running while the configuration says it is off; and `Shared`, because
every other module may rely on it without declaring so.

**Nothing about resolution is cached.** Switching a module off never needs the
cache cleared. The discovery cache holds only what discovery found — ids and
paths, disabled modules included — and an architecture test pins its shape. A
cached dependency graph would be stale the first time somebody edited a
`module.php`, and resolving a few dozen modules in memory costs microseconds.

**A module that uses another module's classes must declare it**, and an
architecture test reads the code to check. Without the declaration it still
works — until the other module is disabled or upgraded, when it fails with a
class-not-found on whichever request first touches the import, instead of
refusing to boot with both modules named.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A new module is not listed | A module cache from `cache:warm` is in use | `php laika cache:clear` |
| A new module is not listed, and no cache | The file is not exactly `modules/<Name>/module.php`, or does not `return` a function | Check the path and the `return` |
| `Class "App\Modules\…" not found`, Linux only | The folder's case does not match the namespace | Rename the folder |
| The boot names two modules | A missing, disabled, conflicting or circular dependency | The message says which of the four |
| A constraint is refused | `1.2` is ambiguous | Write `^1.2` or `1.2.0` |
| Something declared in `onBoot` is ignored | `onBoot` is too late to declare | Declare it in the module closure |

## Why this is not a service provider

A service provider is a class you subclass, whose `register()` and `boot()`
receive the whole container, and which becomes a dumping ground. All three are
broken here:

- **Nothing to subclass.** A module is a closure in a file.
- **Registration cannot read.** `ServiceRegistrar` exposes `bind`, `singleton`,
  `instance` and `factory`, and no read method at all. Service location during
  registration is impossible *by construction*, not by convention.
- **Boot cannot reach the container either.** `onBoot` callbacks are invoked
  through the container, so dependencies arrive as parameters. There is no
  `$app` handle to reach for.
