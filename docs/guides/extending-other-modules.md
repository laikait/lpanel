# Extending other modules

Modules often need to change what another module does: add a discount to its
invoice total, react when it creates a customer, close some of its pages for
maintenance. This guide shows how to do that **without editing the other
module**:

1. react to something that happened, with a **hook**,
2. change a value, with a **filter**,
3. announce your own hooks and filters,
4. apply one rule to many routes,
5. add headers to responses,
6. depend on another module safely,
7. replace another module's templates, routes, services or settings,
8. switch a module off.

Reference: [Hooks and filters](../reference/hooks-and-filters.md), including the
[table of every extension point the framework fires](../reference/hooks-and-filters.md#lifecycle-extension-points),
and [Modules](../reference/modules.md).

## Hooks and filters

Both are named points in the code where other modules can join in.

| | A hook | A filter |
|---|---|---|
| **Says** | "this just happened" | "here is a value; change it if you want" |
| **Example** | `customer.created` | `invoice.total` |
| **Listeners return** | nothing; any return value is ignored | the value, changed or not |
| **Named after** | `<subject>.<what happened>` | the value it carries |

When several listeners join the same point, they run by **priority**, lowest
number first (the default is 10). Listeners with the same priority run in module
order. So the order is always the same, on every request and every server.

## Listen from module.php

```php
$module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
$module->filter('invoice.total', [LoyaltyDiscount::class, 'apply'], priority: 30);
```

The filter's listener:

```php
final class LoyaltyDiscount
{
    public static function apply(Money $total, Invoice $invoice): Money
    {
        return $invoice->customerIsLoyal() ? $total->percentOff(5) : $total;
    }
}
```

It takes 5% off the total for loyal customers, and returns the total unchanged
for everyone else.

Rules:

- **`[Class::class, 'method']` means a static method.** If the method is not
  static, the application refuses to start and says so.
- **A filter listener gets the value first**, then any extra values the code
  passed (here, the invoice).
- **A filter listener must return the value**, even when it changes nothing. In
  debug mode, returning `null` where there was a value throws an error that names
  your listener.

## Listen with an object that has dependencies

A static method cannot receive services. When your listener needs one, such as a
repository or a logger, register it from `onBoot()`, where services are passed
in:

```php
$module->onBoot(static function (Auditor $auditor, HookEngine $hooks): void {
    $hooks->add('customer.created', [$auditor, 'record'], 10, 'Audit');
});
```

- `onBoot()` runs once every module has registered, with the services it asks
  for.
- `$hooks->add(name, listener, priority, module id)`. The last argument is your
  module's id; `module:list` and the profiler show the listener under that name.
- For a filter, do the same with `FilterEngine` and `$filters->add(...)`.

## Announce your own hooks and filters

In a class, ask for the two engines, and fire your points where they belong:

```php
public function __construct(private readonly HookEngine $hooks, private readonly FilterEngine $filters) {}

$this->hooks->do('invoice.paid', $invoice);
$total = $this->filters->apply('invoice.total', $total, $invoice);
```

- `do()` fires a hook, with the values listeners receive.
- `apply()` passes `$total` through every listener, plus `$invoice` for them to
  look at, and gives back the final value.

In `module.php` and in templates, where nothing is passed in, use the global
functions instead: `do_hook()`, `apply_filter()`, `add_hook()`, `add_filter()`.

**Pass objects, not arrays.** With an `Invoice`, listeners know exactly what they
get; with an array, they have to guess the keys.

> **Once another module listens to your hook, its name and arguments are a
> promise.** Changing them breaks that module. Treat it as a breaking change, and
> raise your module's major version.

## One rule for many routes

There is no middleware in this framework. A rule that applies to many routes is
a hook listener that reads a label on the route. First, label the routes with
`meta`:

```php
$routes->post('/contact', [ContactForm::class, 'send'])
    ->meta(['maintenance' => 'blocked']);
```

Then enforce the rule once, for every route, on the hook `dispatch.before`, which
fires just before a route's handler runs:

```php
$module->onBoot(static function (HookEngine $hooks, Config $config): void {
    $hooks->add('dispatch.before', static function (Route $route) use ($config): void {
        if ($route->metaValue('maintenance') === 'blocked' && $config->bool('Desk.maintenance')) {
            throw new HttpException(503, 'The contact form is closed for maintenance.');
        }
    }, 10, 'Desk');
});
```

When the setting `Desk.maintenance` is on, every route labelled
`'maintenance' => 'blocked'` answers `503`.

- **Throwing an `HttpException` refuses the request.** The framework's own `auth`,
  `can`, `csrf` and `rate_limit` labels work exactly this way.
- **A label on a group applies to every route in it**, so
  `$routes->group('/billing', …, meta: ['maintenance' => 'blocked'])` closes a whole
  section.

## Add headers to responses

To change the responses of particular routes, use the filter `dispatch.response`.
It receives the response, the route and the request:

```php
$module->filter('dispatch.response', [ApiFilters::class, 'stampVersion'], priority: 20);
```

```php
public static function stampVersion(Response $response, Route $route): Response
{
    $version = $route->metadata()['version'] ?? null;

    return \is_string($version) ? $response->withHeader('X-Api-Version', $version) : $response;
}
```

This adds an `X-Api-Version` header to responses from routes labelled with a
`version`. [JSON APIs](json-apis.md#deprecate-a-version) uses it in full.

- **A response cannot be changed in place.** `withHeader()` returns a **new**
  response, and that is what the filter must return.
- **To change every response**, including "not found" pages, use the filter
  `response.instance` instead.
- Neither runs for `/assets/...` requests. Those are answered before any module
  is loaded.

## Other useful points

| To | Use |
|---|---|
| react to every error, for metrics or an error tracker | hook `error.reported` (receives `Throwable`, `ErrorContext`, `?Request`) |
| do something once all modules are ready | `onBoot()` in your module, or hook `app.ready` |
| hear about logins and failed logins | hooks `auth.login`, `auth.failed`, `auth.logout` |
| refuse access to one particular record | filter `authorization.decision`; see [Users and permissions](users-and-permissions.md#rules-about-one-particular-record) |
| watch jobs and schedules | hooks `job.*`, `schedule.*`; see [Background work](background-work.md) |

## Depend on another module

If your module uses another module's classes, hooks or services, declare it in
`module.php`:

```php
$module->requires('Billing', '^1.2');        // refuse to boot without it
$module->optionally('Crm', '^2.0');          // work without it; check the version if present
```

- **`requires()`**: your module needs it. If it is missing, switched off, the
  wrong version, or requires your module back, the application refuses to start,
  and names both modules.
- **`optionally()`**: your module works without it; when it is there, its version
  must match.
- `'^1.2'` means version 1.2 or newer, but below 2.0.
- Your module registers **after** the modules it depends on, so your listeners run
  after theirs are in place.

**Declare every module whose classes you use**, `Shared` included. Nothing stops
you from using another module's classes without declaring it, and it works, until
that module is switched off or upgraded. Then it fails on whichever request
touches the class. `ArchitectureTest::test_a_module_declares_every_module_whose_classes_it_uses`
checks this for the showcase modules; copy it for your own.

For an optional module, listening to its hooks needs no check: without the module
they simply never fire. If you do need to know, ask for `ModuleRegistry` in
`onBoot()` and call `isEnabled('Crm')`.

`Shared` always loads first, so it cannot depend on anything. Any other module
may depend on any other.

## Replace what another module shows or does

| To replace | Do this |
|---|---|
| **its templates** | Copy them into `templates/<Name>/` and edit your copy. See [Pages and forms](pages-and-forms.md#change-a-page-you-did-not-write). |
| **a route** | Declare the same method and path in your module, with a different route name (names must be unique). The route declared last wins. |
| **a service** | Register the same interface in your module. The later registration wins; this is how an application replaces the `UserProvider`. |
| **its settings** | Create `config/<Name>.php`. Its keys override the module's defaults. See [Configuration](../reference/configuration.md). |

"Last wins" follows the order modules register in: `Shared`, then the others by
folder name, changed only where `requires()` forces it. Relying on
folder names is fragile, because renaming a folder changes the winner. So declare
`requires()` on the module you replace: then yours always registers after it.

## Switch a module off

```php
// config/modules.php
return ['disabled' => ['Stripe']];
```

A switched-off module does not run at all. Modules that `requires()` it refuse to
start; modules that use it `optionally()` carry on without it.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| The application will not start: the listener "is not static" | `[Class::class, 'method']` needs a static method. | Make it `static`, or register an object from `onBoot()`. |
| In debug mode, a filter "returned null" | The listener forgot to return the value. | Return the value, changed or not. |
| Your listener never runs | Wrong hook name, or your module registered after the point already fired. | Check the name in the [table of extension points](../reference/hooks-and-filters.md#lifecycle-extension-points). |
| Your route or service does not replace the other module's | Your module registers before it. | Add `requires()` on that module. |
| The application will not start, naming two modules | A required module is missing, off, the wrong version, or they require each other. | Read the message; fix the declaration or enable the module. |

More in [Troubleshooting](../troubleshooting.md).
