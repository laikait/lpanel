# Getting started

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

In this tutorial you build one small module, **Notes**, from nothing. When you
finish, you will have:

- a page at `/notes` that lists notes,
- a JSON endpoint at `/api/notes`,
- a console command, `php laika notes:add "Buy milk"`, that writes a note,
- a database table for the notes,
- and a test that checks all of it.

It takes about half an hour. You need to know PHP and Composer, and nothing about
this framework. Every file below is complete: copy it as it is. The whole
tutorial has been run exactly as written.

New words are explained as they come up. They are also in the
[glossary](README.md#glossary).

## Before you start

You need **PHP 8.2 or newer** with SQLite support, and **Composer 2**. Check both:

```bash
php -v                     # PHP 8.2 or newer
php -m | grep -i sqlite    # must print pdo_sqlite
composer --version         # Composer version 2...
```

On Windows, `grep` may not exist; run `php -m` and look for `pdo_sqlite` in the
list.

## 1. Install and run

```bash
composer install
composer serve
```

**You should see:** open `http://127.0.0.1:8080/` in a browser, and the page says
**Your application is running**. (On XAMPP, `http://localhost/framework/` works
too.)

Leave `composer serve` running in this terminal. Open a second terminal in the
same folder for every command below.

See what is there so far:

```bash
php laika module:list    # the modules: only "Shared"
php laika route:list     # the URLs: only "/"
```

## 2. Create the module

**Goal:** a module the framework finds on its own.

A **module** is a folder with a `module.php` file in it. Create the folders
`modules/Notes/`, then the file `modules/Notes/module.php`:

```php
<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

return static function (ModuleContext $module): void {
    $module
        ->name('Notes')
        ->version('0.1.0')
        ->description('Short notes, on a page and on the command line.');

    // The DataSource every repository writes through is bound by Shared.
    $module->requires('Shared', '^0.1');
};
```

```bash
php laika module:list
```

**You should see** your module in the list:

```
  ID      KIND    NAME    VERSION  ROUTES  COMMANDS  HOOKS  FILTERS  REQUIRES
  Shared  shared  Shared  0.1.0    1       0         0      0        -
  Notes   module  Notes   0.1.0    0       0         0      0        Shared ^0.1
```

What just happened:

- **You registered nothing.** The framework found the folder by itself. The
  module's id, `Notes`, is the folder's name. Call yours whatever you like.
- **`requires('Shared', '^0.1')`** says this module needs the `Shared` module,
  version 0.1 or newer. `Shared` provides the storage your notes will be saved
  through.
- **The file returns a function.** The framework calls it with a
  `ModuleContext`, and everything a module can declare is a method on that
  object. See [Modules](reference/modules.md) for the full list.

> **Folder names are part of class names.** A class in
> `modules/Notes/Model/Note.php` is `App\Modules\Notes\Model\Note`,
> with no `composer.json` change. The upper and lower case must match exactly:
> Windows forgives a mistake, Linux does not.

## 3. A model and a repository

**Goal:** a class for a note, and a class that stores notes.

A **model** is a class for one thing your application knows about, with the rules
that keep it valid. Here the rule is: a note cannot be empty. Create
`modules/Notes/Model/Note.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Notes\Model;

use App\Engine\Model\Model;

final class Note extends Model
{
    public function __construct(
        private ?int $id,
        private string $body,
    ) {
        if (\trim($body) === '') {
            throw new \InvalidArgumentException('A note needs something written in it.');
        }
    }

    public function identity(): ?int
    {
        return $this->id;
    }

    public function body(): string
    {
        return $this->body;
    }
}
```

A model does not save itself. When a note is read from the database, the
framework matches each column to the **constructor parameter of the same name**:
the `id` column goes to `$id`, the `body` column to `$body`. So the table in the
next step has exactly those two columns.

A **repository** is the class that reads and saves models. It has no built-in
`find()` or `save()`: you write the methods your application needs, and name them
after what they do. Create `modules/Notes/Data/NoteRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Notes\Data;

use App\Engine\Data\Repository;
use App\Modules\Notes\Model\Note;

final class NoteRepository extends Repository
{
    protected function model(): string
    {
        return Note::class;
    }

    protected function collection(): string
    {
        return 'notes';
    }

    /** @return list<Note> the newest first */
    public function latest(int $limit): array
    {
        $notes = [];

        foreach ($this->query()->orderByDesc('id')->limit($limit)->get() as $note) {
            if ($note instanceof Note) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    public function write(string $body): Note
    {
        $stored = $this->persist(new Note(null, $body));

        return $stored instanceof Note
            ? $stored
            : throw new \LogicException('The repository stored something other than a Note.');
    }
}
```

- `model()` says which class a row becomes, and `collection()` which table it is
  in.
- `latest()` reads the newest notes first.
- `write()` saves a new note. `persist()` gives back the saved note, because a
  new note has no id until the database assigns one.

More in [Models](reference/models.md) and
[Repositories and queries](reference/data.md).

## 4. A database, a table and a command

**Goal:** notes that are kept, and a command that writes one.

### Point the application at a database

Without a database, repositories keep data in memory for one request only. For
notes you want to keep, use a SQLite file. Create a file named `.env` in the
project's root folder, with an **absolute** path:

```bash
# .env  (Windows: DB_DSN=sqlite:C:/xampp/htdocs/framework/system/Runtime/notes.sqlite)
DB_DSN=sqlite:/path/to/framework/system/Runtime/notes.sqlite
```

Replace `/path/to/framework` with the real path to your project. `.env` holds
settings for your machine only. Git ignores it, and the web server never serves
`system/`.

### Create the table with a migration

A **migration** is a file that creates or changes a table. The module keeps its
migrations in its `Database/Migrations/` folder, and each file name starts with
the date and time it was written. Create
`modules/Notes/Database/Migrations/2026_01_01_000000_create_notes.php`:

```php
<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('notes', static function (Table $table): void {
            $table->id();
            $table->text('body');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('notes');
    }
};
```

- `up()` creates the table: an `id` that counts up by itself, and a `body` for
  the text.
- `down()` undoes `up()`, so the migration can be rolled back.
- You describe the table with methods, not SQL. The framework writes the right
  SQL for SQLite, MySQL, PostgreSQL or SQL Server.

Run it:

```bash
php laika migrate --pretend    # the SQL it would run on this database, and nothing run
php laika migrate
php laika migrate:status
```

**You should see:** `--pretend` prints a `CREATE TABLE "notes" ...` statement,
`migrate` says `1 migration(s) ran.`, and `migrate:status` lists the migration as
`ran`.

### Add a command

A **command** is something you run in the terminal. Create
`modules/Notes/Commands/AddNote.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Notes\Commands;

use App\Engine\Cli\Output;
use App\Engine\Hook\HookEngine;
use App\Modules\Notes\Data\NoteRepository;

final class AddNote
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HookEngine $hooks,
    ) {}

    public function __invoke(Output $output, string $body): int
    {
        if (\trim($body) === '') {
            $output->error('A note needs something written in it.');

            return 2;
        }

        $note = $this->notes->write($body);

        $this->hooks->do('note.added', $note);

        $output->success(\sprintf('Note %d written.', $note->identity() ?? 0));

        return 0;
    }
}
```

How a command gets what it needs:

- **The constructor** asks for the classes it uses, here the repository and the
  hook engine. The framework creates them and passes them in. This is called
  **injection**.
- **`__invoke()`** receives what the user typed. `$body` is filled from the
  argument named `body`, which you declare next.
- **The return value** is the exit code: `0` means it worked, `2` means the
  command was used wrongly.
- `$this->hooks->do('note.added', $note)` announces that a note was added. Step 7
  explains what that is for.

### Tell the module about them

Replace `module.php` with:

```php
<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Modules\Notes\Commands\AddNote;
use App\Modules\Notes\Data\NoteRepository;

return static function (ModuleContext $module): void {
    $module
        ->name('Notes')
        ->version('0.1.0')
        ->description('Short notes, on a page and on the command line.');

    // The DataSource every repository writes through is bound by Shared.
    $module->requires('Shared', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(NoteRepository::class);
        $services->bind(AddNote::class);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('notes:add', AddNote::class)
            ->describe('Write a note.')
            ->argument('body', 'What the note says.');
    });
};
```

- **`services()`** lists the classes the framework may create for you.
  `singleton()` means one shared object for the whole request; `bind()` means a
  new one each time.
- **`commands()`** gives the command its name, `notes:add`, a description, and
  one argument, `body`.

Try it:

```bash
php laika notes:add "Buy milk"
php laika notes:add "Call Ada"
php laika notes:add --help
php laika notes:add ""; echo $?     # 2: the command line was wrong
```

**You should see:** `Note 1 written.` and `Note 2 written.`, then a help text
built from your declaration, then an error and the exit code `2`. (In
PowerShell, `""` passes no argument at all; you get "needs the <body> argument"
and still `2`.)

More in [CLI](reference/console.md).

## 5. A page

**Goal:** an HTML page that lists the notes.

### The template

A module's `Templates/` folder is found automatically, and its templates are
named with `@<Name>/`: `modules/Notes/Templates/index.twig` is
`@Notes/index`. Create that file:

```twig
{% extends "layout.twig" %}

{% block title %}{{ title }}{% endblock %}

{% block content %}
<h1>{{ title }}</h1>

{% for note in notes %}
    <p>{{ note.body }}</p>
{% else %}
    <p>No notes yet. Add one with <code>php laika notes:add "Hello"</code>.</p>
{% endfor %}
{% endblock %}
```

- `{% extends "layout.twig" %}` puts this page inside the site's layout,
  `templates/layout.twig`, the same one the home page uses.
- `{{ ... }}` prints a value. Twig escapes it, so a note containing `<script>`
  shows as text instead of running.

### The handler

A **handler** is the code a URL runs. It is an ordinary class; there is no
controller base class. Create `modules/Notes/Http/NotesPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Notes\Http;

use App\Engine\Config\Config;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Template\TemplateManager;
use App\Modules\Notes\Data\NoteRepository;
use App\Modules\Notes\Model\Note;

final class NotesPage
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly TemplateManager $templates,
        private readonly FilterEngine $filters,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $html = $this->templates->render('@Notes/index', [
            'title' => $this->filters->apply('notes.title', 'Notes'),
            'notes' => $this->notes->latest($this->pageSize()),
            'home' => $request->basePath() . '/',
        ]);

        return (new Response($html))->withContentType('text/html');
    }

    /** @return array{data: list<array{id: int|null, body: string}>} */
    public function json(): array
    {
        return ['data' => \array_map(
            static fn(Note $note): array => ['id' => $note->identity(), 'body' => $note->body()],
            $this->notes->latest($this->pageSize()),
        )];
    }

    private function pageSize(): int
    {
        return $this->config->int('Notes.page_size', 20) ?? 20;
    }
}
```

What each part does:

- **`__invoke()`** answers `/notes`. It renders the template with three values
  and wraps the HTML in a `Response`.
- **`json()`** answers `/api/notes`. It returns an array, and the framework turns
  an array into a JSON response.
- **`home`** is passed so the layout's home link works even when the application
  runs in a subfolder, as on XAMPP.
- **`'notes.title'`** and **`page_size`** are two things you have not set up yet.
  The page works without them: `apply()` returns `'Notes'` when nothing changes
  it, and `int()` falls back to `20`. Steps 7 and 8 use them.

## 6. Routes

**Goal:** connect the URLs to the handler.

A **route** connects a URL to a handler. Replace `module.php` with this complete
version. It adds the `NotesPage` service and a `routes()` block:

```php
<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Notes\Commands\AddNote;
use App\Modules\Notes\Data\NoteRepository;
use App\Modules\Notes\Http\NotesPage;

return static function (ModuleContext $module): void {
    $module
        ->name('Notes')
        ->version('0.1.0')
        ->description('Short notes, on a page and on the command line.');

    // The DataSource every repository writes through is bound by Shared.
    $module->requires('Shared', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(NoteRepository::class);
        $services->bind(AddNote::class);
        $services->bind(NotesPage::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/notes', NotesPage::class)->name('notes.index');
        $routes->get('/api/notes', [NotesPage::class, 'json'])->name('notes.api');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('notes:add', AddNote::class)
            ->describe('Write a note.')
            ->argument('body', 'What the note says.');
    });
};
```

- `get('/notes', NotesPage::class)` calls the class's `__invoke()` method.
- `get('/api/notes', [NotesPage::class, 'json'])` calls its `json()` method.
- `name(...)` gives each route a name, so other code can build its URL.

**You should see:** open `http://127.0.0.1:8080/notes`: a heading **Notes** and
your two notes, newest first. Then:

```bash
curl http://127.0.0.1:8080/api/notes
# {"data":[{"id":2,"body":"Call Ada"},{"id":1,"body":"Buy milk"}]}
php laika route:list
```

`route:list` shows both routes, and that they belong to `Notes`. There
is no central routes file: each module declares its own. A handler can return a
`Response`, a string (sent as HTML), an array (sent as JSON), or `null` (an empty
`204` response). See [Routing](reference/routing.md).

## 7. A filter and a hook

**Goal:** see how modules change each other without depending on each other.

### A filter changes a value

A **filter** is a named value that any module may change before it is used. The
page title goes through the filter `notes.title`. Add this line to `module.php`,
just before the closing `};`:

```php
    $module->filter('notes.title', static fn(string $title): string => $title . ' for ' . date('l'));
```

**You should see:** reload `/notes`, and the heading reads *Notes for Tuesday*, or
whichever day it is.

That line could live in any module: this one, or a module written by someone
else. `NotesPage` would not change.

### A hook announces an event

A **hook** is a named moment that other modules can react to. What they return is
ignored. `AddNote` fires the hook `note.added` with the new note. Another module
would react to it by declaring:

```php
$module->hook('note.added', [NoteMailer::class, 'onAdded']);
```

where `NoteMailer::onAdded(Note $note)` is a **static** method, for example one
that emails the note. (To react with an object that needs other services, register
the listener from `onBoot()` instead, where services are injected.) You will not
build `NoteMailer`; the test in step 9 proves the hook fires. See
[Hooks and filters](reference/hooks-and-filters.md).

## 8. Configuration

**Goal:** a setting with a default that each installation can change.

`NotesPage` reads the setting `Notes.page_size`: how many notes a page
shows. Give it a default in `module.php`, again just before the closing `};`:

```php
    $module->config(['page_size' => 20]);
```

That is the module's **default**. Whoever runs the application can change it
without touching the module, in a file named after the module's id. Create
`config/Notes.php`:

```php
<?php

return ['page_size' => 1];
```

**You should see:** reload `/notes`, and only the newest note is left.

The file in `config/` always beats the module's default: the module suggests, the
installation decides. `php laika config:list` shows every setting and where it
came from. Delete `config/Notes.php` when you are done. See
[Configuration](reference/configuration.md).

## 9. A test

**Goal:** a test that checks the command, the page, the hook and the API.

Tests start the real application. Create `tests/Feature/NotesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Modules\Notes\Commands\AddNote;
use App\Modules\Notes\Model\Note;
use App\Tests\Support\TestCase;

final class NotesTest extends TestCase
{
    public function test_a_note_added_on_the_command_line_is_on_the_page(): void
    {
        $app = $this->notes();

        $status = $app->container()->get(AddNote::class)($this->console(), 'Buy milk');
        $page = $app->handle(Request::create('GET', '/notes'));

        self::assertSame(0, $status);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('<p>Buy milk</p>', $page->body());
    }

    public function test_adding_a_note_announces_it(): void
    {
        $app = $this->notes();
        $heard = [];

        add_hook('note.added', static function (Note $note) use (&$heard): void {
            $heard[] = $note->body();
        });

        $app->container()->get(AddNote::class)($this->console(), 'Call Ada');

        self::assertSame(['Call Ada'], $heard);
    }

    public function test_the_api_lists_the_newest_note_first(): void
    {
        $app = $this->notes();
        $add = $app->container()->get(AddNote::class);
        $add($this->console(), 'first');
        $add($this->console(), 'second');

        $response = $app->handle(Request::create('GET', '/api/notes'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(
            ['data' => [['id' => 2, 'body' => 'second'], ['id' => 1, 'body' => 'first']]],
            $response->data(),
        );
    }

    /** The application as it ships, plus this module, on a database that lives for one test. */
    private function notes(): Application
    {
        $app = $this->shippedApplication([
            'app' => ['debug' => false],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();

        $this->migrate($app);

        return $app;
    }

    /** Somewhere for a command to print that is not the test runner's screen. */
    private function console(): Output
    {
        $stream = \fopen('php://memory', 'w');
        self::assertIsResource($stream);

        return new Output($stream);
    }
}
```

```bash
vendor/bin/phpunit tests/Feature/NotesTest.php
```

**You should see:** `OK (3 tests, 10 assertions)`.

How the test works:

- **`shippedApplication()`** starts everything in `modules/`, with the settings
  you pass on top. Here that is an in-memory database, so every test starts empty
  and never touches your notes file.
- **`$this->migrate($app)`** runs the migrations, so the `notes` table exists.
- **`$app->handle(...)`** sends a request straight to the application. Nothing
  goes over the network, and no server needs to be running.

> **Never name a test helper `output()`.** PHPUnit's `TestCase` already has a
> method with that name, and PHP refuses to load the test.

Check your module with the same tools the framework uses:

```bash
vendor/bin/phpstan analyse modules/Notes tests/Feature/NotesTest.php
vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override modules/Notes
```

PHPStan finds type mistakes; php-cs-fixer checks the coding style and shows a diff
of anything to change. Drop `--dry-run` to let it make the changes.

## What you built

```
modules/Notes/
  module.php                 everything the module adds, in one file
  Model/Note.php             a note, and the rule that it cannot be empty
  Data/NoteRepository.php    latest() and write()
  Database/Migrations/2026_01_01_000000_create_notes.php
                             the notes table, on any database
  Commands/AddNote.php       notes:add, which fires note.added
  Http/NotesPage.php         GET /notes and GET /api/notes
  Templates/index.twig       @Notes/index
tests/Feature/NotesTest.php
```

Deleting the folder `modules/Notes/` removes all of it: the routes, the
command, the filter and the templates. Nothing outside the folder knew it
existed. (Only the `notes` table stays in the database; roll it back first with
`php laika migrate:rollback` if you want it gone too.)

## If it doesn't work

A page that fails shows only "500 Internal Server Error" until you turn on
**debug mode**: add `APP_DEBUG=true` to `.env`, and the page shows the real
message, like the ones below. Console commands show the message either way.

| What you see | Why | Fix |
|---|---|---|
| `could not find driver` | PHP has no SQLite support. | Enable `extension=pdo_sqlite` in `php.ini`, then check with `php -m`. |
| `no such table: notes` | The migration has not run on this database. | Run `php laika migrate`. If you changed `DB_DSN`, run it again for the new file. |
| The notes are gone after every command | `DB_DSN` is missing or relative, so each run uses a different database, or none. | Put an **absolute** path in `.env`, as in step 4. |
| `Class "App\Modules\Notes\..." not found` | A folder or file name's case does not match the namespace. | Compare each folder name with the `namespace` line, letter by letter. |
| `Unable to find template "layout.twig"` | `templates/layout.twig` was moved or renamed. | Put it back, or extend a layout that exists. |
| php-cs-fixer changes the `use` lines | Imports must be in alphabetical order. | Run it without `--dry-run`, or copy the files from this page as they are. |
| `composer check` fails `DefaultPagesSliceTest` | Those tests describe a fresh install with only `Shared`, and a module of yours changes that. | Nothing is wrong with your module. An application adapts or removes the framework's tests that describe a fresh install. |

More symptoms and fixes are in [Troubleshooting](troubleshooting.md).

## Where next

- **Build something real:** the [guides](README.md#guides), for pages and forms,
  a JSON API, storing data, background work, logins and permissions.
- **Look something up:** the [reference](README.md#reference), one page per part
  of the framework.
- **See a bigger module:** `tests/Fixtures/Showcase/Plugins/Example/` uses every
  part of the framework at once. It is a test fixture, not an application;
  reading its `module.php` is the fastest tour there is.

<!-- {% endraw %} -->
