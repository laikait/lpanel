# Testing

This guide shows how to test what you build on the framework:

1. where tests go, and how to run them,
2. start a real application in a test,
3. send it requests, forms and JSON,
4. follow a logged-in browser, with cookies and CSRF,
5. use a database that starts empty every time,
6. check hooks, jobs and console commands,
7. mistakes that are easy to make.

**Nothing needs mocking.** A test starts the real application, with the real
modules, and handles a request without any web server. One request takes about a
millisecond.

## Where tests go

| Folder | For |
|---|---|
| `tests/Feature/` | tests that start the application and send it requests |
| `tests/Unit/` | tests of one class on its own |

Both use the namespace `App\Tests\`, which loads from `tests/`.

```bash
vendor/bin/phpunit tests/Feature/DeskTest.php   # one file
vendor/bin/phpunit --filter inbox               # tests whose name contains "inbox"
composer test                                   # everything
```

## Start the application

Extend `App\Tests\Support\TestCase`, and write a small helper that starts an
application for each test:

```php
final class DeskTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['app']['debug'] ??= false;

        return $this->shippedApplication($config)->boot();
    }
}
```

`shippedApplication()` loads everything in `modules/`. The array you pass is
settings, in the same shape as the files in `config/`: passing
`['Desk' => ['maintenance' => true]]` is the same as a
`config/Desk.php` that returns `['maintenance' => true]`.

For every test it also:

- keeps sessions and rate-limit counts **in memory**, so tests write nothing to
  `system/` and no test inherits another test's `429`;
- leaves PHP's error handler alone, so PHPUnit still reports warnings;
- undoes global `add_hook()` calls when the test ends, so one test's listeners
  cannot reach the next.

> **Set `app.debug` to `false`** when the test is about what a visitor sees. In
> debug mode an error shows the framework's diagnostic page instead of your error
> template.

## Send a request

```php
$response = $this->app()->handle(Request::create('GET', '/contact'));

self::assertSame(200, $response->status());
self::assertStringContainsString('<h1>Contact us</h1>', $response->body());
```

`Request::create()` takes the method, the path, and options:

| Option | Example |
|---|---|
| `query` | `['page' => 2]`, or write it in the path: `/api/v1/messages?page=2` |
| `body` | an array is sent as a form; a string is sent as it is |
| `headers` | `['Accept' => 'application/json', 'Content-Type' => 'application/json']` |
| `cookies` | `['session' => $id]` |
| `files` | `['avatar' => new UploadedFile(...)]` |
| `server` | `['SCRIPT_NAME' => '/framework/index.php']` to test the application in a subfolder, with the path `/framework/contact` |

For JSON, send the body as a string with the content type, and read the answer
back as data:

```php
$response = $app->handle(Request::create('POST', '/api/v1/customers', [
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    'body' => '{"name":"Ada","email":"ada@example.test"}',
]));

self::assertInstanceOf(JsonResponse::class, $response);
self::assertSame(201, $response->status());
self::assertSame('Ada', $response->data()['data']['name']);
```

## Forms, cookies and CSRF

One application can handle several requests in a row, and sessions last as long
as it does. So a test can act like a browser, as long as it carries the cookies
itself.

This test sends the contact form and checks that the "thank you" message appears
once:

```php
private function cookie(Response $response, string $name): ?string
{
    foreach ($response->cookies() as $cookie) {
        if ($cookie->name === $name) {
            return $cookie->value;
        }
    }

    return null;
}

public function test_a_sent_message_redirects_and_flashes_once(): void
{
    $app = $this->app();
    $token = (string) $this->cookie($app->handle(Request::create('GET', '/contact')), Csrf::COOKIE);

    $sent = $app->handle(Request::create('POST', '/contact', [
        'cookies' => [Csrf::COOKIE => $token],
        'body' => ['_token' => $token, 'email' => 'ada@example.test', 'body' => 'Hello'],
    ]));

    $session = (string) $this->cookie($sent, SessionManager::DEFAULT_COOKIE);
    $cookies = [Csrf::COOKIE => $token, SessionManager::DEFAULT_COOKIE => $session];

    $next = $app->handle(Request::create('GET', '/contact', ['cookies' => $cookies]));
    $after = $app->handle(Request::create('GET', '/contact', ['cookies' => $cookies]));

    self::assertSame(303, $sent->status());
    self::assertSame('/contact', $sent->header('Location'));
    self::assertStringContainsString('Thank you. We will reply soon.', $next->body());
    self::assertStringNotContainsString('Thank you.', $after->body());
}
```

What it does:

1. Asks for the form, and takes the CSRF token from the `XSRF-TOKEN` cookie.
2. Sends the form, with the token both as a cookie and as the `_token` field.
3. Keeps the session cookie from the answer.
4. Asks for the page twice more with those cookies: the message shows the first
   time and is gone the second, because flash data lasts one request.

**Logging in works the same way:** send your login route the token and the
details, keep the `session` cookie, and send it with later requests. An API
client instead sends `'headers' => ['Authorization' => 'Bearer <token>']`.

A `POST` **without** a matching token is a `403`. That is worth one test per
form.

## A database

With no database configured, repositories keep data in memory belonging to that
one application, so every `app()` starts empty. Put test data in through your own
repository methods:

```php
$messages = $this->app()->container()->get(MessageRepository::class);
$messages->receive(['email' => 'a@example.test', 'body' => 'one']);
```

To test real SQL, use an in-memory SQLite database, which is equally fresh for
each application, and create the tables with your migrations:

```php
$app = $this->app(['database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]]]);
$db = $app->container()->get(ConnectionManager::class)->connection();

$this->migrate($app);    // every module's migrations, as `php laika migrate` runs them
```

The test then has the same tables as production, made by the same files. See
[Migrations and seeders](../reference/database.md#migrations-and-seeders).

## Hooks and filters

Listen inside the test, after the application has started:

```php
$heard = [];

add_hook('note.added', static function (Note $note) use (&$heard): void {
    $heard[] = $note->body();
});

// ... do the thing ...

self::assertSame(['Call Ada'], $heard);
```

`add_filter()` does the same for a value, which lets a test change what another
module would see.

## Jobs

The default queue store is `sync`, so a job runs inside `push()` and its effects
are there as soon as the handler returns.

To check **what** was queued rather than what it did, listen to `job.queued`:

```php
$queued = [];

add_hook('job.queued', static function (mixed $envelope, Job $job) use (&$queued): void {
    $queued[] = $job;
});
```

To queue jobs without running them, start the application with
`['queue' => ['store' => 'memory']]`.

## Console commands

A command is an ordinary class, so the simplest test calls it:

```php
private function console(): Output
{
    $stream = \fopen('php://memory', 'w');
    self::assertIsResource($stream);

    return new Output($stream);
}

$status = $app->container()->get(AddNote::class)($this->console(), 'Buy milk');
```

`console()` gives the command somewhere to print that is not the test runner's
screen.

To test the command **line** itself — how arguments are read, `--help`, the exit
code for a typo — run it through the console kernel, the way `php laika` does:

```php
/** @return array{int, string} the exit code, and everything printed */
private function command(Application $app, string ...$arguments): array
{
    $container = $app->container();
    $stream = \fopen('php://memory', 'r+');
    self::assertIsResource($stream);

    $kernel = new ConsoleKernel(
        $container->get(CommandRegistry::class),
        $container->get(CommandDispatcher::class),
        $container->get(HookEngine::class),
        $container->get(ErrorHandler::class),
        new Output($stream),
    );

    $status = $kernel->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

    \rewind($stream);

    return [$status, (string) \stream_get_contents($stream)];
}
```

```php
[$status, $output] = $this->command($this->app(), 'notes:add', '');
self::assertSame(ConsoleKernel::USAGE, $status);    // 2
```

The exit codes are `ConsoleKernel::SUCCESS` (0), `FAILURE` (1), `USAGE` (2) and
`UNKNOWN` (127).

> `ConsoleKernel` and `CommandRegistry` are **internal** classes. Using them in a
> test is fine; using them in a module is not, because internal classes change
> without notice.

## Check your module with the framework's tools

`composer check` looks at `engine/` and `tests/`. Point the same tools at your
module:

```bash
vendor/bin/phpstan analyse modules/Desk
vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override modules/Desk
```

One framework test already reads your modules:
`DocumentationTest::test_no_module_uses_an_internal_class` fails when anything in
`modules/` uses a class that `STABILITY.md` marks Internal. If your module seems
to need one, that is worth raising as a question about the framework rather than
working around.

## Traps

- **Some method names are taken.** PHPUnit's `TestCase` marks `run()`, `output()`,
  `count()`, `name()`, `status()`, `size()` and others as `final`. Naming your
  helper after one is a fatal error the moment the test file loads, with a
  message about overriding a final method. Pick another name, as `command()`
  above does.
- **Tests that describe a fresh install.** The framework's own suite contains
  `DefaultPagesSliceTest`, which checks that only `Shared` ships and that `/` is
  the default home page. Once `modules/` holds your modules, those tests describe
  a different application. Adapt or delete them; they are not about your module.
- **Debug mode changes what an error looks like.** With `app.debug` left on, a
  test that expects your 500 page gets the framework's diagnostic page.
- **One application, one set of cookies.** A test that sends a form must carry
  the CSRF and session cookies itself, as shown above; nothing does it for you.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| "Cannot override final method" when the file loads | A helper is named after a PHPUnit method. | Rename it; see Traps. |
| `403` in a form test | The CSRF cookie or the `_token` field is missing. | Fetch the page first and send the cookie back, as above. |
| `no such table` in a test | The migrations have not run for this application. | Call `$this->migrate($app)` after starting it. |
| Data from one test appears in another | Both used the same application. | Start a fresh application per test; in-memory storage goes with it. |
| The framework's own tests fail after you add a module | They describe a fresh install. | See Traps. |

More in [Troubleshooting](../troubleshooting.md).
