# Troubleshooting

Symptoms, what causes them, and how to fix them. Find your symptom in the
section that matches where it happens.

**Start with these two commands.** They answer most questions:

```bash
php laika about          # version, whether caches are in use, modules, connections
APP_DEBUG=1 php laika …  # run a command with full error messages and a trace
```

Outside debug mode the framework hides error details from browsers, API clients
and the console alike, because an error message can give away paths, settings and
database details. Debug mode on your own terminal is safe. **Debug mode on a
public web server is not.**

## Installing and starting

**Every page under Apache is a 404, but `composer serve` works.**
`mod_rewrite` is not loaded, or `AllowOverride` is `None` for the folder, so
`.htaccess` is ignored. Check with
`grep -E 'rewrite_module|AllowOverride' httpd.conf`. Where the project folder
itself is served, that `.htaccess` is also what keeps your source out of reach —
see [Deployment and security](operations/deployment.md).

**Only the home page works; every other route is a 404.** The same cause, for
`public/.htaccess`: with `AllowOverride None` nothing sends requests to
`index.php`.

**`composer.json` or `engine/` can be downloaded.** The document root is the
project folder and its `.htaccess` is not being read. Point the document root at
`public/`, or enable `mod_rewrite` and `AllowOverride All`. Then run the checks
in [Deployment and security](operations/deployment.md).

**Links and stylesheets point at the wrong folder.** The framework works out the
base path from `SCRIPT_NAME`. Behind a proxy that rewrites paths, set
`http.base_path` in `config/http.php`.

**`composer serve` answers everything with "The document root must be
public/".** The built-in server was started without `-t public`. Use
`composer serve`, or `php -S 127.0.0.1:8080 -t public server`.

**`Class "Normalizer" not found`, or Composer asks for `ext-intl`.** PHP's intl
extension is not loaded; route paths are normalised with it. In XAMPP, remove the
`;` before `extension=intl` in `php\php.ini` and restart Apache. On Debian or
Ubuntu: `apt install php8.3-intl` (use your PHP version).

**A route with a non-English word in it is a 404 when it has a constraint.**
Scripts such as Bengali or Hindi use combining vowel signs, which `\p{L}` does
not match. Use `[\p{L}\p{M}\p{N}-]+` — see
[Paths in any language](reference/routing.md#paths-in-any-language).

**It is slow: 50 ms for a simple page.** Opcache is off. XAMPP ships with it off.

**Every request is a 500 after a change.** Checks that run before any request
have failed: an unknown or circular module dependency, a route requiring a
capability nobody declared, a schedule naming a command that does not exist, or a
malformed `.env` line. Run `APP_DEBUG=1 php laika about`: the message names the
module, route or variable.

## Modules

**A new module does not appear in `php laika module:list`.**

- A module cache from `cache:warm` exists and debug is off. Run
  `php laika cache:clear`.
- The file is not exactly `modules/<Name>/module.php`, or it does not
  `return` a function.
- It is listed in `modules.disabled`. `module:list` shows disabled modules under
  the table.

**`Class "App\Modules\…" not found`, on Linux only.** The folder's upper
and lower case does not match the namespace. Windows and macOS ignore case;
Linux does not.

**"Nothing declares the capability …", naming a route.** A route has
`meta(['can' => …])` naming a capability no module declared — usually a typo.
Declare it with `$module->access()`, or correct the name.

**"…() is not static, so [… ::class, '…'] cannot be used as a listener".**
`[Class::class, 'method']` is a static call. Make the method static, or register
an object as the listener from `onBoot()` — see
[Extending other modules](guides/extending-other-modules.md#listen-with-an-object-that-has-dependencies).

**A filter throws about a null value, in debug mode only.** A filter listener
returned nothing where there was a value. It is missing its `return`; the message
names the listener.

**My `/` route is ignored.** It is probably named `home`, which the shared module
already uses. Route names must be unique, so the application refuses to start.
Give yours another name.

## Requests

**A form post is refused with 403.**

- The form has no `_token` field, or it does not match the `XSRF-TOKEN` cookie.
- The browser is blocking the cookie.
- It is a request from another site: the `Origin` header names a different host.

**A form or upload is refused with 413.** Either the body is bigger than
`MAX_REQUEST_BYTES`, or it was bigger than php.ini's `post_max_size` and PHP threw
it away before the application saw it. The message says which. Raise the one that
refused it (and `upload_max_filesize` for files).

**A JSON endpoint answers 415.** The request did not send
`Content-Type: application/json`, and the handler calls `requirePayload()`.

**API requests with a token get 401 under Apache, but work under `composer
serve`.** Apache drops the `Authorization` header. Keep the shipped `.htaccess`
rules, or add `CGIPassAuth On` to the virtual host.

**401 or 403?** 401 means nobody is logged in. 403 means someone is, but does not
have the capability. `php laika auth:access` shows who has what.

**429 Too Many Requests while developing.** Rate-limit counts are files under
`system/Security` and outlive the process. Delete that folder.

**An API client gets an HTML error page.** It did not send
`Accept: application/json`.

**A 406.** The handler called `negotiate()` and can offer nothing the client's
`Accept` header allows.

## Templates and assets

**The wrong template is rendered.** Folders are searched in order: `templates/`,
then its override folder for that module (`templates/<Name>/`), then the
module itself. In each, `.twig` is tried before `.php`. `php laika template:list`
prints the order.

**A template under `templates/assets/` "is not a plain name".** `assets/` holds
static files, never views. Move the template anywhere else under `templates/`.

**My error page is not shown.** Debug mode always shows the framework's
diagnostic page. Set `APP_DEBUG=false` to see yours. An error template that
itself throws also falls back to the built-in page.

**A module's stylesheet is a 404.** It must be in the module's `assets/` folder,
have an allowed extension, and be linked with
`asset()->module('<Name>', 'css/x.css')`. `php laika asset:list` shows what is
published. HTML files are never served as assets.

**The browser keeps an old stylesheet.** The URL must come from `asset()`, which
adds a content hash. A hand-written `/assets/...` URL has no version, so browsers
keep the old file.

## Data

**Everything I saved is gone on the next request.** No database is configured, so
repositories use memory that lasts one request. Set `DB_DSN`.

**"no such table".** The migrations have not run on this database. Run
`php laika migrate`, and `php laika migrate:status` to see what is pending. A
table no module migrates is created by nothing; see
[Storing data](guides/storing-data.md#create-the-tables). In a test, call
`$this->migrate($app)` after starting the application.

**A model's property is never filled.** Columns must match the constructor's
**parameter names** exactly, including case.

**`related()` throws.** Related records are loaded on purpose, with `keysFor()`
and `link()`, before `related()` is called. There is no lazy loading.

**A queue worker sees old data.** Within one process, the same row is the same
object. Call `ModelManager::flush()` at the start of a job that must read fresh
rows.

**Renaming a column fails on MySQL or MariaDB.** `RENAME COLUMN` needs MySQL 8.0
or MariaDB 10.5.2. On an older server the change is refused before anything runs;
upgrade, or write the `CHANGE` yourself with `$tables->raw(..., 'mysql')`.

## Configuration

**A change in `config/` has no effect.** A configuration cache exists.
`php laika config:list --sources` says where each value came from. Run
`php laika cache:clear`.

**A configuration key is "of the wrong type".** Typed reads do not convert
values: write `30`, not `'30'`, in a config file. The message names the key.

**An environment variable stops the boot.** Booleans must be
`true/false/yes/no/on/off/1/0`, and numbers must be numbers. The message names
the variable.

**`.env` is ignored.** A real environment variable of the same name always wins.

## Background work

**Jobs are never processed.** With `QUEUE_STORE=file` or `database`, a
`php laika queue:work` process must be running, for that queue. With the default
`sync`, jobs run immediately inside the request instead.

**Jobs are queued but a worker on another machine never sees them.**
`QUEUE_STORE=file` keeps jobs on one machine. Use `database`, and run
`php laika migrate` so the `jobs` table exists.

**A job runs twice.** A worker died, or the job took longer than
`QUEUE_TIMEOUT`, so the job became available again and another worker took it.
Make jobs safe to repeat, and raise the timeout for slow ones.

**A scheduled task stopped running.** The process running it was killed and its
lock is still held. `php laika schedule:list` shows it as running; release it
with `php laika schedule:unlock --id=<id>`.

**Every scheduled task runs twice.** `schedule:run` is in cron on two machines.
It belongs on exactly one.

**A scheduled command's output is nowhere.** It goes to the log, and nothing is
logged until a writer is configured. Check with `php laika log:status`.

## Sessions and logins

**Users are logged out at random.** Several machines with `SESSION_STORE=file`.
Use `database`.

**Logged out after exactly two hours.** `SESSION_IDLE` is 7200 seconds by
default.

**Two users see each other's data.** A shared (singleton) service asked for
`Session` in its constructor and kept the first request's session. Ask for
`Session` as a handler or method parameter instead.

**A session value comes back as an array instead of the object I stored.**
Session data is stored as JSON. Store an id, and load the object again.

## Logs

**Nothing is logged.** No writer is configured by default. See
[Running in production](operations/running.md#before-the-first-deployment).

**Logging stopped.** A writer that fails — a full disk, a permission problem — is
switched off for the rest of the process. `php laika log:status` says which one
and why.

**The database log writer stopped, saying the table does not exist.** Run
`php laika migrate` on that connection. Until then nothing is written by that
writer, which is why a second writer, such as `file`, is worth having.

## MCP

**A client sees no tools, or gets "Unknown tool.", 401 or 404 from `/mcp`.** See
the table in [MCP](reference/mcp.md#troubleshooting). `php laika mcp:list` shows
what is exposed and what each capability needs, and the `mcp` log channel records
every refusal.

## Tests

**"Cannot override final method PHPUnit\Framework\TestCase::…".** A test helper
is named after a method PHPUnit marks final, such as `run()`, `output()`,
`count()`, `name()` or `status()`. Rename your helper.

**Framework tests fail after adding a module.** `DefaultPagesSliceTest` describes
a fresh install, which yours no longer is. See [Testing](guides/testing.md#traps).

**A PHP warning fails a test.** PHPUnit is set to fail on warnings, notices and
deprecations. Fix the warning rather than silencing it.
