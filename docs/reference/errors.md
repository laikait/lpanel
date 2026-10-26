# Errors

Everything that goes wrong arrives in one place and leaves as one document: a
handler's validation failure, a route that did not match, a PHP warning, an
uncaught exception, and even a fatal that killed the process.

What differs is how it is rendered, and that is chosen by **who is reading**:

| Reader | Sees |
|---|---|
| `Browser` | your own error template, or the framework's built-in page; in debug mode, Whoops |
| `Api` | the JSON error document |
| `Console` | two lines, plus a trace in debug mode |

**Debug mode is a separate question.** Who is reading decides *to whom*; debug
mode decides *how much*. Keeping the two apart is what makes "this message is
fine on a terminal and not in a response" possible to express at all.

## What may be said, and to whom

| | Browser | Api | Console |
|---|---|---|---|
| An `HttpException` message | shown | shown | shown |
| Any other framework message | withheld | withheld | **shown** |
| Your own exception's message | withheld | withheld | withheld |
| Class, file, line, trace | debug only | debug only | debug only |

The middle row is the one worth explaining. `Command "customer:sync" is already
registered by Example` is exactly what an operator needs, and exactly
what an anonymous client should not have — it is an inventory of the
application. An operator already has the source, the configuration and a
directory listing, so withholding it from them protects nobody and costs them an
afternoon.

Outside debug mode a withheld message is replaced **wholesale** with the status
text, never filtered. Filtering means guessing which substrings are secret, and
that guess is wrong eventually.

### Writing an exception that withholds its message

`FrameworkException` messages are disclosed by default, because the framework
wrote them. A factory that quotes something it did **not** write calls
`withheld()`:

```php
return (new self(\sprintf('Could not open "%s": %s', $name, $previous->getMessage())))
    ->withheld();
```

Nearly always what it quotes is `$previous->getMessage()`, and the reason is
concrete: PDO quotes the DSN back on a connection failure, and a DSN is one
keystroke from a password.

An architecture test fails if a factory interpolates another exception's message
without withholding it. That is the tripwire for the one mistake here that costs
something real.

## The application's own error page

Create either of these and it is used:

```
templates/errors/404.twig     a lost visitor
templates/errors/error.twig   everything else
```

A template named after the status wins; `errors/error` catches the rest; with
neither, the framework's built-in page renders. A branded 404 costs one file, and
it extends your ordinary layout because it is an ordinary template.

Both are handed two variables:

| Variable | Is |
|---|---|
| `error` | the `ErrorDocument`: status, title, and the message if one may be shown |
| `home` | the front page **as the failing request addresses it**, so "Back to the home page" still works under Apache in a subfolder |

Neither repeats the requested path back to the visitor, so the address bar
cannot put markup on your page.

Two rules keep an error page from making things worse:

1. **A template that throws falls back to the built-in page.** A typo in
   `errors/error` is discovered the day the first 500 happens, and at that point
   the visitor needs a page far more than the framework needs to be right about
   which one.
2. **Debug mode always shows the built-in page.** An error template is written
   for a visitor; if it won, the day somebody added a branded 500 page would be
   the day stack traces stopped appearing.

The consequence of the second rule is that you preview your error templates with
debug **off**, which is the right way round.

**A theme can have its own error pages**, in `errors/` inside the theme folder,
for example `templates/admin/dark/errors/404.twig`. A route with
`meta(['theme' => 'admin/dark'])` makes the theme active before the guards run,
so its 401, 403 and 419 pages are themed, and `templates/errors/` is the
fallback, rendered in the theme's layout. An unknown URL has no route to ask, so
`templates.error_themes` maps a path prefix to a theme for those:
`['/admin' => 'admin/dark']`. See [Themes](templates.md#error-pages).

The built-in page has no `<link>`, no `<script>` and no asset URL, and an
architecture test keeps it that way. It has to render when the thing that broke
is the asset pipeline.

## The debug page: Whoops

With `APP_DEBUG=true` and [`filp/whoops`](https://github.com/filp/whoops)
installed, a browser gets the Whoops page instead of the built-in one: the
source around every frame, the request, and the environment. It is a regular
dependency in `composer.json`, so every install has it.

**It is the only thing that displays an error.** PHP's `display_errors` is off
in debug as well as production, so PHP never prints its own "Fatal error:" in
front of the page. That covers every way a request can fail:

- an exception or a warning in a handler;
- a fatal (out of memory, a timeout, a call to an undefined function). Memory
  and time are raised before the page is built, so an exhausted request still
  gets Whoops and a 500, not PHP's message and a 200;
- a failure before the application exists, such as a `config/*.php` file or a
  `.env` file that throws. The handler is installed before configuration is
  read, and takes `APP_DEBUG` from the environment until it has the rest.

Anything a handler printed before it failed (an `echo`, a `var_dump`) is
discarded, so it never comes out above the page.

- **Debug only.** Outside debug mode Whoops is never used, whatever is
  installed.
- **Rendering only.** Whoops is not registered as PHP's handler. The framework's
  `ErrorHandler` still catches, reports on `error.reported` and responds; Whoops
  only turns the exception into HTML. JSON clients and the console are
  unchanged.
- **Secrets are masked.** Every cookie value, every environment value, and any
  server or form field whose name looks like a secret (`KEY`, `SECRET`, `PASS`,
  `TOKEN`, `DSN`, `AUTH`, `SESSION`, ...) shows as asterisks. The source code
  and the exception message are shown as they are, so a message carrying a
  secret still shows it: that is what debug mode is for.
- **It cannot make things worse.** Without the package, or if Whoops itself
  fails, the built-in page renders, and says why in a note at the top: the
  package is missing, or what Whoops threw (an unknown `APP_EDITOR` is the usual
  one). If even rendering the error fails, a plain 500 names both errors in
  debug and neither in production.
- **Editor links.** `APP_EDITOR=phpstorm` (or `vscode`, `sublime`, `idea`, ...)
  makes every file path open in your editor. Paths are shown relative to the
  project root, and frames in `modules/`, `templates/` and `config/` are marked
  as yours.

Never turn debug on in production. Masking protects the obvious secrets; the
source code and your data are still on the page.

## Listening for errors

Every handled error fires a hook:

```php
$module->hook('error.reported', [Telemetry::class, 'record']);
```

The listener receives the **throwable itself** — not a formatted string, so a
listener that wants the previous exception or the trace does not have to parse
them back out of a sentence — plus the `ErrorContext` and the request.

That is the seam the framework's own `Logging\ErrorLog` attaches to, which is
why there is no logger interface here to implement. See [Logging](logging.md).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Your error template is ignored | Debug mode is on, and always shows the debug page | `APP_DEBUG=false` |
| Debug mode shows the plain built-in page, not Whoops | The note at the top of the page says why: Whoops is missing from `vendor/`, or it threw (usually an unknown `APP_EDITOR`) | `composer install`, or fix `APP_EDITOR` |
| PHP's own "Fatal error:" text appears | The error happened before `Bootstrap::create()` (in `vendor/autoload.php` or `public/index.php` itself), where no framework code runs yet | Fix the file the message names; PHP's log has it too |
| An error page shows the built-in one in production | Your template threw while rendering | Check it renders on its own |
| An API client gets an HTML error page | It did not send `Accept: application/json` | Send the header |
| A message says only the status text | It was withheld, as designed | Read it on the console, or with `APP_DEBUG=1` |
| A blank page, status 200 | A fatal before the handler was registered | Check `php -l` on the file, and the web server's error log |

## Why it works this way

### Rendering and recording are separate

An architecture test asserts that nothing under `engine/Error/` calls
`error_log()`, `syslog()` or `fopen()`. The moment a file handle appears there,
rendering and recording are one thing again, and changing either means touching
both.

### Taking over from PHP

`register()` installs four things:

- **`display_errors` off**, in production and in debug. In production this is
  the single most security-relevant line in the whole area: left on, PHP writes a
  warning — with the absolute path of the file that raised it — straight into the
  response body, above the doctype, with no way to intercept it afterwards. In
  debug it was the reason Whoops sometimes did not appear: PHP prints a fatal
  before the shutdown handler runs, so the page came after PHP's message or not
  at all. `error_reporting` stays at `E_ALL` and `log_errors` is left alone, so
  the handler sees everything and PHP's log still records it.
- **Errors become `ErrorException`**, so they can be caught like anything else,
  while a suppressed notice stays suppressed.
- **An exception handler**, so an uncaught throwable renders a page instead of
  printing a trace.
- **A shutdown handler**, so a fatal produces a 500 rather than a blank 200.

Bootstrap installs them **first**, before `.env` and `config/*.php` are read,
with a provisional debug flag from `APP_DEBUG`; `configure()` hands over the
real configuration and the template-aware page once they exist. A test, or a
config file, that sets `app.handle_errors` to `false` gets PHP's handlers back
through `unregister()`.

The shutdown handler needs memory it will not have, so `RESERVED_MEMORY` is 256 KB held
from registration and released at the top of the shutdown handler. The size was
measured, not guessed: at 32 KB the handler ran out of memory a second time and
produced **nothing at all**, because building the `ErrorException` captures a
backtrace and the document and page are strings. 64 KB was enough for
production; 256 KB leaves room for a debug trace on a deep stack. Whoops needs
more than any reserve, so for an out-of-memory fatal the handler also raises
`memory_limit` by `FATAL_MEMORY_HEADROOM` (64 MB), and for a timeout it allows
`FATAL_TIME_HEADROOM` (30 s) more.

The handler is also told which request is in flight:

```php
$this->container->get(ErrorHandler::class)->serving($request);
```

That is the one piece of mutable state in the class, and it earns its place.
PHP's exception and shutdown handlers take no arguments, so when a fatal happens
between the kernel returning and the last byte being written, this is the only
way to know whether a browser or a program is waiting. Without it, an API client
receives an HTML page.
