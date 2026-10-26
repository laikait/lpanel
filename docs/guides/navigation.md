# Navigation menus

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

A menu -- a header, a footer, an admin sidebar, a user panel -- is a small
tree: items, some nested under others, some visible only to certain roles.
This guide builds one as a Nav module, following the pattern in
`tests/Fixtures/Modules/Nav/`, which you can copy wholesale into your own
`modules/Nav/`.

It is not shipped with the framework. `modules/` ships with `Shared` and
nothing else -- see [What is not built](../what-is-not-built.md) -- so this is
something you add, the same way you would add any other module.

## 1. One table

```php
<?php // modules/Nav/Database/Migrations/2026_01_01_000000_create_nav_items.php

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('nav_items', static function (Table $table): void {
            $table->id();
            $table->bigInteger('parentId')->nullable()->index();
            $table->string('location', 40)->index();
            $table->string('label', 120);
            $table->string('url', 255)->nullable();
            $table->string('route', 120)->nullable();
            $table->string('capability', 120)->nullable();
            $table->integer('order')->default(0);
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('nav_items');
    }
};
```

`location` is what tells one menu from another -- `header`, `footer`,
`admin_sidebar`, `user_panel`, or any name you like. One table serves every
menu in the application.

`parentId` is deliberately **not** a foreign key. This module is meant to be
deletable: nothing else in the application may depend on it existing, so
nothing else may point a foreign key at its table, and it points none out
either. A `parentId` naming a row that is gone -- its parent deleted, or the
whole table dropped and recreated -- is not an error; the repository below
treats it as a root instead of losing the item.

**Column names match the model's constructor exactly, including case** --
`parentId`, not `parent_id`. There is no conversion between a row and a
model; see [Models](../reference/models.md).

## 2. A model and two supporting types

```php
<?php // modules/Nav/Model/NavItem.php

namespace App\Modules\Nav\Model;

use App\Engine\Model\Model;

final class NavItem extends Model
{
    public function __construct(
        private ?int $id,
        private ?int $parentId,
        private string $location,
        private string $label,
        private ?string $url = null,
        private ?string $route = null,
        private ?string $capability = null,
        private int $order = 0,
        private bool $enabled = true,
    ) {}

    public function identity(): ?int { return $this->id; }
    public function parentId(): ?int { return $this->parentId; }
    public function location(): string { return $this->location; }
    public function label(): string { return $this->label; }
    public function url(): ?string { return $this->url; }
    public function route(): ?string { return $this->route; }
    public function capability(): ?string { return $this->capability; }
    public function order(): int { return $this->order; }
    public function isEnabled(): bool { return $this->enabled; }
}
```

`NavNode` is the *assembled* tree -- an item plus its already-visible
children -- which is not a row NavRepository could have read back from
storage, so it is not a Model:

```php
<?php // modules/Nav/Model/NavNode.php

namespace App\Modules\Nav\Model;

final class NavNode
{
    /** @param list<NavNode> $children */
    public function __construct(
        public readonly NavItem $item,
        public readonly array $children,
    ) {}
}
```

## 3. The repository

```php
<?php // modules/Nav/Data/NavRepository.php

namespace App\Modules\Nav\Data;

final class NavRepository extends Repository
{
    public function __construct(
        DataSource $source,
        ModelManager $models,
        private readonly Cache $cache,
        private readonly Authorizer $authorizer,
        private readonly Config $config,
    ) {
        parent::__construct($source, $models);
    }

    protected function model(): string { return NavItem::class; }
    protected function collection(): string { return 'nav_items'; }

    /** @return list<NavNode> */
    public function tree(string $location, Identity $identity): array
    {
        $key = \sprintf('tree.%s.%s', $location, \implode(',', $identity->roles));

        return $this->cache->namespace('nav')->remember(
            $key,
            fn (): array => $this->visible($this->build($this->rowsFor($location)), $identity),
            $this->config->int('Nav.cache_ttl', 300),
        );
    }

    // rowsFor(), build() and visible() are private: fetch the location's rows,
    // group them by parentId into NavNode, then drop what $identity may not
    // see. The full listing is in tests/Fixtures/Modules/Nav/Data/NavRepository.php.
}
```

Every enabled row for the location is fetched in one query and grouped in
PHP -- a menu is small enough that this is simpler than a recursive query,
and it behaves the same on every database. Visibility is checked per item
with `Authorizer::allows()`: an item whose `capability` an identity does not
hold is dropped, along with its children, so a menu never links to a page it
then refuses. A capability that is not declared at all -- a typo, or one a
module used to own -- hides the item instead of throwing, so a stale row
cannot break every page that renders the menu.

Caching is scoped and keyed by role set: `$cache->namespace('nav')`, key
`tree.<location>.<roles>`, so a guest and an administrator never share a
cached answer. There is no automatic invalidation hook; a write method you
add (there is none yet -- see below) calls
`$this->cache->namespace('nav')->clear()` itself.

## 4. The module

```php
<?php // modules/Nav/module.php

use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Modules\Nav\Data\NavRepository;

return static function (ModuleContext $module): void {
    $module->name('Nav')->version('0.1.0')->description('Navigation menus, by location.');

    $module->requires('Shared');

    $module->config(['cache_ttl' => 300]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(NavRepository::class);
    });
};
```

## 5. Rendering a menu

**There is no global `nav()` Twig function**, and this is deliberate, not an
oversight. Two rules rule it out:

- The engine may never reference a module (an architecture test enforces
  this), so nothing under `engine/` can be taught what "Nav" is.
- `TemplateView` -- the `view` object every template receives -- is
  deliberately not a handle on the container. If a template needs something,
  the handler passes it in.

So a handler injects `NavRepository` like any other service, and passes the
tree into `render()` like any other data. **Twig has no `url()` function
either** -- calling a route name is a job for the *handler*, not the
template, same as `route` itself -- so a node whose `route` is set needs its
href resolved before it reaches Twig:

```php
final class ProductPage
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly NavRepository $nav,
        private readonly AuthManager $auth,
        private readonly Router $router,
    ) {}

    public function show(Request $request): Response
    {
        $identity = $this->auth->identity();

        return (new Response($this->templates->render('products/show', [
            'nav_header' => $this->withHrefs($this->nav->tree('header', $identity)),
        ])))->withContentType('text/html');
    }

    /**
     * @param list<NavNode> $nodes
     *
     * @return list<array{item: NavItem, href: string, children: array}>
     */
    private function withHrefs(array $nodes): array
    {
        return \array_map(fn (NavNode $node): array => [
            'item' => $node->item,
            'href' => $node->item->route() !== null
                ? $this->router->url($node->item->route())
                : ($node->item->url() ?? '#'),
            'children' => $this->withHrefs($node->children),
        ], $nodes);
    }
}
```

```twig
{% for node in nav_header %}
  <a href="{{ node.href }}">{{ node.item.label }}</a>
  {% if node.children %}
    <ul>
      {% for child in node.children %}
        <li><a href="{{ child.href }}">{{ child.item.label }}</a></li>
      {% endfor %}
    </ul>
  {% endif %}
{% endfor %}
```

Every page that extends a shared `layout.twig` passes `nav_header` the same
way it already passes `title` -- more typing per handler than a global
function, and the price of not teaching the engine what a module is. The
admin UI below (`NavAdminPages::links()`) resolves URLs the same way, once
per request, rather than per row in the template.

## 6. The admin UI

`tests/Fixtures/Modules/Nav/` ships a full CRUD-plus-reorder screen over
`nav_items`: `NavAdminPages` (create/edit/delete/reorder), `NavItemSchema`
(validation), and two templates (`index.twig`, `form.twig`). Copy the whole
directory to get it, or just the routes below into your own module.

**It carries no `auth` meta at all, on purpose:**

```php
$routes->get('/nav', [NavAdminPages::class, 'index'])
    ->name('nav.index')->meta(['rate_limit' => '60/1m']);
$routes->post('/nav', [NavAdminPages::class, 'store'])
    ->name('nav.store')->meta(['rate_limit' => '20/1m']);
// ...and four more: nav.create, nav.edit, nav.update, nav.delete, nav.move
```

So it is usable the moment the module is installed, with no login screen to
build first. **CSRF still applies** -- it is opt-out, not opt-in, so nothing
here is quite as open as it looks -- but there is no `auth` or `can` check
of any kind. Anyone who can reach `/nav` on this host can rewrite every menu
in the application.

**This is meant for local development or a trusted internal network, not a
public production host.** Before this goes anywhere public, add `auth`/`can`
meta to each route yourself -- one line per route:

```php
$routes->get('/nav', [NavAdminPages::class, 'index'])
    ->name('nav.index')
    ->meta(['auth' => true, 'can' => 'nav.manage', 'rate_limit' => '60/1m']);
```

declaring `nav.manage` the same way any other capability is declared (see
[Users and permissions](users-and-permissions.md), `AccessCollector`), and
grant it to whichever role should be trusted to edit menus.

Reordering is two buttons, not drag-and-drop: **Up**/**Down** each POST to
`nav.move` with a `direction` field, and `NavRepository::move()` swaps
`order` with the adjacent sibling in the same location and under the same
parent. A location's items are always listed by `order`, so nothing else
needs to change.

Every write ends with `$cache->namespace('nav')->clear()` inside
`NavRepository` itself -- `create()`, `update()`, `delete()`, and `move()`
all call it, so a page rendered by `tree()` never shows a stale menu after
an edit made through this UI.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A property is never filled | The migration's columns must match `NavItem`'s constructor exactly, including case | Rename the column, or the parameter |
| An item never appears | It is disabled, its `location` does not match what you asked for, or the identity lacks its `capability` | Check `enabled`, `location`, and `Authorizer::allows()` for that identity |
| A capability error at request time | `capability` names something nothing declares | Declare it with `AccessCollector`, or clear the column |
| An edit does not show up | The cache has not been cleared | Call `$cache->namespace('nav')->clear()` after every write |
| `403` when submitting `/nav`'s form | The CSRF token is missing or wrong -- there is no `auth` meta, but CSRF still applies | Load the form first; a token printed by one request is valid on the next |
| Anyone can rewrite the menu | There is no `auth`/`can` meta on `/nav` -- see "The admin UI" above | Add `auth`/`can` meta to each route, or keep this off a public host |

<!-- {% endraw %} -->
