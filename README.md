# App Framework

A PHP framework for large backend applications: ERP, billing, hosting control
panels, SaaS backends, admin platforms and API-heavy systems.

Your application is built from **modules**. A module is one folder under
`modules/` that brings its own pages, API endpoints, console commands, database
tables and background jobs. Delete the folder and all of it is gone, because
nothing outside the folder refers to it.

> **Status: 2.1.2, the first release.** Everything in the specification is built
> except the demo application (Phase 29), which is postponed. Every public API is
> **Experimental**; nothing is marked Stable yet. See
> [API stability](docs/contributing/releases.md),
> [What is not built](docs/what-is-not-built.md) and
> [Implementation status](docs/contributing/development.md#implementation-status).

## What you need

- **PHP 8.2 or newer**, with the `intl`, `json`, `mbstring` and `pdo` extensions.
  On XAMPP, `intl` is switched off: open `php\php.ini` and remove the `;` in front
  of `extension=intl`.
- **Composer 2**, PHP's package manager.
- **A PDO driver for your database**, for example `pdo_mysql`. `pdo_sqlite` is
  enough to try everything, including the tests.
- **`fileinfo`**, only if your application accepts file uploads.

To see which extensions you have, run `php -m`.

## Install and run it

```bash
composer install     # download the framework's dependencies
composer serve       # start a development server
```

Open `http://127.0.0.1:8080/`. You should see **Your application is running**.

On XAMPP you can skip `composer serve`: the application also answers at
`http://localhost/framework/` without any setup.

Two more commands you will use all the time:

```bash
php laika            # list every console command
composer check       # coding standard + static analysis + tests
```

## What a fresh install contains

| Folder | What is in it |
|---|---|
| `modules/Shared/` | The one module that ships. It owns the home page, `/`. |
| `modules/<Name>/` | Not there yet. Each module you write is a folder here, named whatever you like — `modules/Billing/`, `modules/Stripe/` — with a `module.php`. |
| `templates/` | The site's pages: a layout, the home page and the error pages, as Twig files. |
| `lang/` | Translations: `en.php`, `bn.php` and `countries.php`. See [Localization](docs/reference/localization.md). |
| `config/` | Your settings, as PHP files. Empty until you need one. |
| `engine/` | The framework itself. You do not edit it. |
| `public/` | The only folder the web server may serve: `index.php` and public assets. |
| `system/` | Files the framework writes: logs, caches, sessions. |

So a fresh install answers two pages and a health check:

```bash
curl -i http://127.0.0.1:8080/               # the home page
curl -i http://127.0.0.1:8080/health         # 200 when healthy, 503 when not (JSON)
curl -i http://127.0.0.1:8080/no/such/page   # the "not found" page
curl -i -H 'Accept: application/json' \
        http://127.0.0.1:8080/no/such/page   # the same error, as JSON
```

To replace the home page, declare a `/` route in a module of your own. See
[The default pages](docs/reference/templates.md#the-default-pages).

There is no example application in `modules/`. The documentation's examples use a
module called `Example`, which lives in `tests/Fixtures/Showcase/` as a test
fixture: worth reading, but not part of an installation.

## Updating it later

Your application is a copy of the framework, so `composer update` does not bring
a new version. `php laika framework:update` does, without touching your modules,
templates or tests — see [Updating the framework](docs/operations/updating.md).

## Where to go next

| You want to | Read |
|---|---|
| learn the framework | [Getting started](docs/getting-started.md): build a small module, step by step, in about half an hour |
| build something | the [guides](docs/README.md#guides): pages and forms, JSON APIs, storing data, background work, users and permissions, extending modules, testing |
| look up how one part works | the [reference](docs/README.md#reference), one page per part |
| put it on a server | [Running in production](docs/operations/running.md), [Deployment and security](docs/operations/deployment.md), [Troubleshooting](docs/troubleshooting.md) |
| change the framework itself | [Contributing](docs/contributing/README.md) |

A fresh install has no user accounts and no login page. You add your own; see
[Users and permissions](docs/guides/users-and-permissions.md).

## How it is built

What happens to one request:

```
public/index.php                the web server hands every request here
   -> engine/bootstrap.php      sets up the framework
   -> Application::boot()       finds the modules and lets each one register
   -> HttpKernel::handle()      turns the Request into a Response
        -> Router               finds which handler the URL belongs to
        -> Dispatcher           builds the handler and calls it
        -> Hooks / Filters      let other modules react or change values
```

Web pages and JSON APIs go through the same kernel. Console commands
(`php laika ...`) use the same setup, without the HTTP part.

It is deliberately not MVC, and not a copy of Laravel, Symfony or CodeIgniter.
Modules are the main way to split an application; hooks and filters are how
modules extend each other; and database access is written out rather than
hidden behind an ORM.

## License

MIT. See [LICENSE](LICENSE).
