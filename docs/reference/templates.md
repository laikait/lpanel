# Templates

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

A *template* is a file that produces HTML. The site's templates live in
`templates/`; a module can ship its own.

## Render one

```php
template()->render('profile', $data);                              // templates/profile.twig
template()->render('customer/profile', ['customer' => $customer]); // templates/customer/profile.twig
template()->render('@Example/invoice', $data);              // the Example module's invoice
```

A name is a path under `templates/`, without the extension. Folders are just
part of the name: `admin/panel/user` is `templates/admin/panel/user.twig`.

**No extension is written at the call site.** That is not a convenience: it is
what lets a template move from PHP to Twig, or a Twig one be replaced by a PHP
one, without a single caller changing. The manager knows every extension any
registered engine claims, and tries them all.

**Rendering produces a string, never a response.** Your handler wraps it:

```php
return (new Response($this->templates->render('layout', $data)))->withContentType('text/html');
```

which is what lets the same template be rendered into an email, a PDF pipeline
or a test assertion. An architecture test keeps this layer from seeing `Request`
or `Response` at all.

## Layouts

A Twig page uses Twig's own inheritance, as the shipped pages do:

```twig
{% extends "layout.twig" %}
{% block title %}Welcome{% endblock %}
{% block content %}<h1>Your application is running</h1>{% endblock %}
```

The PHP engine has no `@extends`, `@section` or `@yield`, and will not grow
them — that is the line between a template engine and a reimplementation of
Blade. A PHP page renders to a string and hands it to the layout:

```php
$content = $this->templates->render('@Example/customers', ['customers' => $rows]);
$html    = $this->templates->render('layout', ['title' => 'Customers', 'content' => $content]);
```

The shipped `layout.twig` prints a `content` value when no block replaces it, so
it works for PHP pages too. `content` is printed unescaped, because it is markup
another template already escaped; every other value in the layout is escaped by
Twig.

## Resolution, and how overriding works

First hit wins, in this order:

| | Folder |
|---|---|
| 0. the active [theme](#themes), if any | `templates/<theme>/` or `templates/<area>/<theme>/` |
| 1. the application | `templates/` |
| 2. the override of a namespace | `templates/<namespace>/` |
| 3. the module itself | `modules/Example/Templates/` |

So a site replaces a module's invoice by creating

```
templates/Example/invoice.php
```

and the module is never edited, asked or told. Its own copy stays as the
fallback, which is what makes it safe for the module to keep shipping one.

Only `templates/` takes part in rule 2. A module must not be able to override
another module by guessing a folder name, or which template wins would come down
to discovery order.

The search is **folder-major, not extension-major**: every extension is tried in
the highest-precedence folder before dropping to the next. So the site's `.php`
beats a module's `.twig`, and the other loop order would let the module win by
virtue of its file extension.

`php laika template:list` prints the whole search path in order, which is most
of the answer to "which file is actually being rendered".

### Module namespaces

A module with a `Templates/` folder gets a namespace automatically. There is
nothing about templates in any `module.php` — the same bargain as assets.

| Module | Namespace |
|---|---|
| `modules/Shared/Templates/` | `@Shared/…` |
| `modules/Example/Templates/` | `@Example/…` |
| `modules/Stripe/Templates/` | `@Stripe/…` |

The dot is not decoration: a Twig namespace cannot contain a slash, and the two
engines have to agree on how a template is named.

The shared module *does* get a namespace here, unlike in the asset layer — a
shared partial is an ordinary thing to want, and unlike a URL, there is a name
for it.

## Themes

A theme is a folder under `templates/` with its own views and its own
`assets/`. There are two shapes, and `templates/` itself stays the default:

```
templates/                        the site; always the fallback
templates/assets/                 /assets/template/…
templates/dark/                   theme "dark"
templates/dark/assets/            /assets/template/dark/…
templates/admin/dark/             theme "admin/dark": an area and a theme
templates/admin/dark/assets/      /assets/template/admin/dark/…
```

### Register a theme

**Themes are registered, never discovered.** A folder under `templates/` is not
a theme until something says it is. List the themes in configuration:

```php
// config/templates.php
return [
    'themes' => ['dark', 'admin/dark'],
];
```

or, when they live in the database, name a class that returns them:

```php
'theme_provider' => App\Themes\DatabaseThemes::class,
```

```php
use App\Engine\Database\ConnectionManager;
use App\Engine\Template\ThemeProvider;

final class DatabaseThemes implements ThemeProvider
{
    public function __construct(private readonly ConnectionManager $db) {}

    public function themes(): iterable
    {
        $rows = $this->db->connection()->table('themes')->where('enabled', 1)->get();

        return \array_column($rows, 'name');
    }

    public function errorThemes(): iterable
    {
        return []; // see "Error pages" below
    }
}
```

The provider is built through the container and asked once per request,
**including asset requests**, which load no module. Cache its answer if the
themes rarely change. Both lists are used together.

A name is `theme` or `area/theme`, each part matching `[A-Za-z0-9][A-Za-z0-9_-]*`.
Slashes at either end are trimmed wherever a name is accepted (config, route
meta, `use()`, `asset()->template()`), so `/app/default/` is the theme
`app/default`; a doubled slash in the middle is still refused.
Some names are refused: `assets`, `emails` and `errors`, which are folders of
`templates/` itself, and a module id such as `Example` (`templates/Example/`
already overrides that module). Emails never follow a theme; see
[Mail](mail.md#building-a-message).

### Use a theme for a page

A route, or a whole group, names its theme in meta:

```php
$routes->group('/admin', static function (RouteCollector $admin): void {
    $admin->get('/', [Dashboard::class, 'index']);
    $admin->get('/users', [Users::class, 'index']);
}, meta: ['theme' => 'admin/dark']);
```

For a theme chosen at runtime (per user, per tenant), call the registry
yourself before rendering:

```php
$themes->use($user->theme);   // App\Engine\Template\ThemeRegistry, injected
```

Every request starts with no theme, so one request's theme never carries into
the next one served by the same process.

With a theme active, `render('customer/profile')` looks in
`templates/admin/dark/customer/profile.*` first and then in `templates/`.
Layouts and includes follow along: `{% extends "layout.twig" %}` in a page
from `templates/` picks up `templates/admin/dark/layout.twig` when the theme has
one. A module override works the same way: `@Example/row` looks in
`templates/admin/dark/Example/` before `templates/Example/`.

**Assets are always named, and never fall back:**

```twig
<link rel="stylesheet" href="{{ view.asset().template('admin/dark', 'css/app.css') }}">
```

That reads `templates/admin/dark/assets/css/app.css` and nothing else; a file
missing there is a 404, not the copy in `templates/assets/`.

### Error pages

A theme can have its own error pages, in `errors/` inside the theme. For an
error raised after a route was chosen (a 403 from a guard, a 500 from a
handler), the route's theme is already active, so `errors/404` is looked for in
the theme first and `templates/errors/` is the fallback, rendered inside the
theme's layout.

An error raised **before** any route matches has no route to ask: an unknown
URL, a 405, maintenance mode. For those, map a path prefix to a theme:

```php
'error_themes' => ['/admin' => 'admin/dark'],
```

so `/admin/nonexistent` gets the admin theme's 404. The longest prefix wins, a
prefix matches whole segments (`/admin` does not match `/administrator`), and
`/` matches everything. A provider's `errorThemes()` is merged over this list.

## Twig by default, PHP second

`twig/twig` is a runtime dependency and the default engine. Bootstrap registers
the Twig engine first and the PHP engine second, and **the order is the
precedence**: where one folder holds both `home.twig` and `home.php`, the Twig
file renders.

Folder precedence still comes first — the site's `.php` beats a module's
`.twig` — so the engine order only decides ties inside one folder. An
architecture test pins both the dependency and the order.

Twig is the default because it escapes everything it prints unless told not to,
which turns a forgotten escape from a hole into a visible `&lt;`. PHP templates
stay because a module that already ships `.php` views should keep working, and
because they need nothing compiled or cached before they run.

Nothing outside `TwigTemplateEngine.php` mentions Twig, and an architecture test
says so. The manager talks to engines through `TemplateEngine`, so replacing
Twig later is one class and one line in Bootstrap.

Twig's loader mirrors the registry — the same folders, the same order, module
namespaces as Twig namespaces — so `{% extends "layout.twig" %}` and
`{% include "@Example/row.twig" %}` resolve exactly where the manager
would have resolved them, override rule included. If the two disagreed, a
template found by one would be missing to the other.

## What a PHP template gets

Every key of the data as a local variable, plus `$view` and `$e`. Nothing else:
the file is included from a static closure, so `$this` does not exist inside a
template and the engine's internals cannot be reached from one.

```php
<h1><?= $e($title) ?></h1>
<?php foreach ($customers as $customer): ?>
    <li><?= $e($customer->name()) ?></li>
<?php endforeach ?>
<?= $view->render('partials/pager', ['page' => $page]) ?>
```

**`$e` is short on purpose.** PHP templates do not escape anything on their own,
and that is the single largest hazard in using them. The framework does not fix
it by inventing a syntax — that road ends at a compiler nobody asked for — it
fixes it by making the correct call short enough that there is no excuse.

Escaping is by context, because it is not one operation:

```php
<?= $e($text) ?>                          <!-- between tags -->
<a title="<?= $e->attr($tip) ?>">         <!-- an attribute -->
<script>const id = <?= $e->js($id) ?>;</script>
<a href="?q=<?= $e->url($term) ?>">
<?= $e->raw($trustedHtml) ?>              <!-- named so it shows in a diff -->
```

Data can never overwrite `$e` or `$view`. A data key called `e` would otherwise
turn every escape call in the application into a call to whatever the handler
passed, which is a security bug with a very long fuse. The shadowed value is
still readable as `$view->get('e')`.

`$view` is deliberately tiny: `render()`, `exists()`, `asset()`, `escaper()`,
`has()`, `get()`, `data()`. It is **not** a handle on the container. A template
that can resolve arbitrary services is a template that can run a query, and then
"what does this page do" stops having an answer you can read in the handler. If
a template needs something, the handler passes it in.

A partial gets exactly the data it is given and never sees its parent's
variables, because a partial that could is a partial whose contract is
"whatever happened to be in scope".

## Filters and functions a module offers

Besides `local`, a template can call whatever a module declares in its
`module.php`:

```php
$module->templates(static function (TemplateHelperCollector $templates): void {
    $templates->filter('money', [Money::class, 'format']);
    $templates->function('route', [Links::class, 'route']);
});
```

A filter transforms the value in front of it; a function produces a value from
its arguments. Only the spelling differs:

| | Twig | PHP template |
|---|---|---|
| Filter | `{{ total\|money }}`, `{{ total\|money('EUR') }}` | `<?= $e($view->filter('money', $total)) ?>` |
| Function | `{{ route('home') }}` | `<?= $e->attr($view->call('route', 'home')) ?>` |

A callback is a closure, a function name, or `[Class::class, 'method']`. For a
class and a method that is not static, the container builds the object the first
time it is used, and after that one object serves every call in the process.

**Output is escaped unless you say otherwise.** Pass `true` as the third argument
only when the callback returns markup it has already escaped; Twig then prints it
as it is. A PHP template escapes, or calls `$e->raw()`, exactly as it does for any
other value.

Names are lower case, digits and underscores (`money`, `money_short`). `local`
is reserved, a name Twig already provides cannot be replaced, and two modules
offering the same name is an error that names both. `php laika template:list`
lists every helper, its module and its callback.

## The default pages

```
templates/layout.twig        shared by every page below
templates/home.twig          GET /, declared by modules/Shared
templates/errors/404.twig    any path nothing answers
templates/errors/error.twig  every other error status
templates/assets/css/theme.css   their stylesheet, /assets/template/css/theme.css
```

The front page is an ordinary route in the shared module — there is no global
routes file for it to live in — rendering `home` with the request's `home` URL.
It says nothing about the machine or the version, because a default page is
public by definition.

It is meant to be replaced, and replacing it needs no edit to the framework:

- **Declare `/` in your own module.** Every other module registers after `Shared`,
  and the router keeps the last route declared for a method and path, so yours
  answers. Give it a name other than `home`: route names are unique, and that
  one is taken.
- **Or edit `templates/home.twig`.**

The 404 page is what any unmatched path gets from a browser. A client that asks
for JSON gets the error document instead, and debug mode shows the built-in
diagnostic page, so a trace is never hidden behind a pretty one.

## Templates are not web-readable

`templates/` is outside the document root, alongside `engine/` and `modules/`,
for exactly the same reason: a PHP view the web server can reach is a PHP file
it will execute, and a Twig view it can reach is source it will hand out.

`templates/assets/` stays reachable as `/assets/template/…` through the asset
manager, which is the only way in — and it is never a view. `render('assets/…')`
is refused, and so is `render('admin/dark/assets/…')` for every registered
theme, so a `.php` file among the static files cannot run as a template.

A request for `/templates`, or anything under it, reaches the application like
any other path, so a module may declare a route there. A path with no route is
the application's 404, whether or not a file of that name exists.

## Settings

| Key | Default | What it does |
|---|---|---|
| `templates.cache` | `false` | Twig's compilation cache, under `system/Cache/templates`. PHP templates never need it — opcache already has them. |
| `templates.themes` | `[]` | Themes to publish: `dark` is `templates/dark/`, `admin/dark` is `templates/admin/dark/`. See [Themes](#themes). |
| `templates.theme_provider` | `null` | A `ThemeProvider` class, built through the container, for themes kept in the database. |
| `templates.error_themes` | `[]` | Path prefix to theme, for error pages raised before a route matched: `['/admin' => 'admin/dark']`. |

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| The wrong template renders | Another folder earlier in the search path has that name | `php laika template:list` prints the order |
| "is not a plain name", for `assets/…` | `templates/assets/` holds static files, never views | Move the template elsewhere under `templates/` |
| A template is not found | The name is a path under `templates/`, without the extension | Check the path, not the extension |
| "No theme … is registered" | Themes are never discovered | Add it to `templates.themes` or return it from the provider |
| A theme's page does not change anything | No theme is active for that route | Add `meta(['theme' => …])` to the route or its group |
| `/admin/nonexistent` shows the site's 404 | No route matched, so no route chose a theme | Map `/admin` in `templates.error_themes` |
| A module's override is ignored | Only `templates/<namespace>/` overrides a module | Put the file there, not in another module |
| `<b>` shows as text in a PHP template | Twig escapes; PHP does not, and `$e()` escaped it | Use `$e->raw()` for markup you trust |
| A partial cannot see a variable | Partials get only what they are passed | Pass it explicitly |
| An edited template does not change | Twig's compilation cache is on | `php laika cache:clear` |

<!-- {% endraw %} -->
