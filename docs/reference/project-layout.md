# Project layout

Where everything lives, and which folder your own code goes in.

## Where your code goes

| You are writing | It goes in |
|---|---|
| A feature or an integration: pages, commands, jobs | `modules/<Name>/`, any name you choose |
| Something two modules both need | `modules/Shared/` |
| The look of the site | `templates/` |
| The application's translations | `lang/` |
| This installation's settings | `config/` |
| Your own stylesheets and scripts | `public/assets/` |

You never edit `engine/` — that is the framework — and nothing your application
writes goes in `system/`, which the framework manages.

## The whole tree

```
public/                the document root, and nothing else is web-reachable
  index.php            front controller
  .htaccess            routes what is not a file to index.php
  assets/              the application's own stylesheets and scripts
.htaccess              forwards into public/ where the document root cannot be changed
server                 dev router: php -S 127.0.0.1:8080 -t public server
laika                  CLI entry point: php laika <command>
.env.example           every environment variable, with its assumed value
framework.json         which framework release this is, file by file; framework:update reads it
config/                this installation's decisions; absent until there is one
                       (config/Billing.php configures Billing)
engine/                the framework
  bootstrap.php        builds the application for either context
  Bootstrap/ Core/ Container/ Http/ Routing/ Dispatch/
  Module/ Hook/ Filter/ Model/ Schema/ Data/ Database/
  Asset/ Template/ Support/ Config/ Error/ Logging/ Cli/
  Cache/ Queue/ Scheduler/ Security/ Observability/
  System/              commands, processes, cron, services, files on the server
  MCP/                 tools, resources and prompts that modules declare, over STDIO and HTTP
  Localization/        translations, locale resolution and country detection
  Network/             IPv4 and IPv6 addresses, CIDR blocks, netmasks, allowlists
templates/             the site's views: render('customer/profile') is customer/profile.twig
  layout.twig          the layout every default page extends
  home.twig            the front page until a module claims /
  errors/              the 404 and generic error pages
  assets/              the site's static files, /assets/template/... (never a view)
lang/                  translations: en.php, bn.php, ... and countries.php (see localization.md)
modules/
  Shared/              cross-module capability, registers first; owns /
    Model/User.php     a shared domain model
    Auth/              what a user IS here: the provider and the login routes
    Schema/            the pagination contract every list endpoint shares
    Data/              a shared repository
  <Name>/              any other module, named whatever you like: Billing, Stripe
    module.php         declares it; a folder without one is not a module
    Templates/         its views, @<Name>/... (optional)
    assets/            its static files, /assets/module/<Name>/... (optional)
    lang/              its translations, keyed <Name>.* (optional)
system/                cache, logs, sessions, queued work (not web-readable)
  Cache/               compiled configuration, the module list, cached data
  Queue/               jobs waiting for a worker, and the ones that failed
  Schedule/            one file per schedule lock, while it is held
  Security/            rate-limit counters, one file per key
  Sessions/            one file per session, named by hash rather than by id
  Commands/            concurrency slots for system commands, one lock file each
  Logs/                where the file writer puts records
tests/
  Benchmark/           composer bench; never served, with the rest of tests/
  Fixtures/Showcase/   Example and ExampleGateway: every subsystem in
                       one application, booted by the feature tests
```

**Folders are created when they are used, never in advance.** A fresh
installation has no `config/` and only `modules/Shared/`; the rest appears
when you make it.

## Autoloading

| Folder | Namespace |
|---|---|
| `engine/` | `App\Engine\` |
| `modules/` | `App\Modules\` — so `modules/Shared/` holds `App\Modules\Shared\` and `modules/Billing/` holds `App\Modules\Billing\` |

A new module autoloads with no `composer.json` change and no custom autoloader:
`modules/Billing/Api/Invoices.php` declares
`App\Modules\Billing\Api\Invoices` and simply works.

Two consequences worth knowing:

- **Upper and lower case in folder names matters on Linux.** Windows and macOS
  will not catch a mismatch between a folder name and its namespace segment, so
  an architecture test compares the names as stored on disk, and the Linux CI
  job backs it up. Renaming only the case of a folder on Windows or macOS needs
  two `git mv` steps (`shared` → `_Shared` → `Shared`), or git records nothing.
- **Never run `composer dump-autoload --classmap-authoritative` in
  production.** It switches off the PSR-4 fallback and breaks any module added
  after the dump. `--optimize` alone is fine, and recommended.
