# Documentation

Start with the section that matches what you are doing. If a word is new to you,
look it up in the [glossary](#glossary) at the bottom of this page.

Everything here describes version 2.1.2. What is public, and how stable it is,
is listed in [`STABILITY.md`](../STABILITY.md).

## New to the framework

1. **[Getting started](getting-started.md).** Build a small module from nothing,
   one step at a time: a page, a JSON endpoint, a console command, a database
   table and a test. About half an hour.
2. **[What is not built](what-is-not-built.md).** Things the framework leaves
   out on purpose, so you do not spend time looking for them.

## Guides

Each guide walks through one task in your own application.

- **[Pages and forms](guides/pages-and-forms.md)**: templates, layouts,
  stylesheets, forms with validation, file uploads, and changing pages you did
  not write.
- **[JSON APIs](guides/json-apis.md)**: API routes, versions, validating input,
  errors, pagination.
- **[Storing data](guides/storing-data.md)**: connecting to a database, creating
  tables, reading and saving records, transactions.
- **[Background work](guides/background-work.md)**: jobs that run later, queue
  workers, retries, tasks on a schedule.
- **[Users and permissions](guides/users-and-permissions.md)**: your own user
  accounts, logging in, protecting pages, roles, API tokens.
- **[Extending other modules](guides/extending-other-modules.md)**: changing what
  another module does with hooks and filters, and depending on another module.
- **[Navigation menus](guides/navigation.md)**: a Nav module for header,
  footer, admin sidebar and user-panel menus, told apart by location and
  gated by capability.
- **[Database backups](guides/backups.md)**: a Backup module for MySQL,
  PostgreSQL, SQLite and SQL Server, `backup:make`/`backup:restore`, and what
  each driver actually needs.
- **[Testing](guides/testing.md)**: testing pages, forms, databases, jobs and
  commands.

## Reference

How each part of the framework works, one page per part. Read a page here when
a guide links to it, or when you need the details.

| Area | Pages |
|---|---|
| Structure | [Modules](reference/modules.md) · [Hooks and filters](reference/hooks-and-filters.md) · [Project layout](reference/project-layout.md) |
| HTTP | [Routing](reference/routing.md) · [REST](reference/rest.md) · [OpenAPI](reference/openapi.md) · [Errors](reference/errors.md) · [HTTP client](reference/http-client.md) |
| Output | [Templates](reference/templates.md) · [Assets](reference/assets.md) · [Localization](reference/localization.md) |
| Data | [Models](reference/models.md) · [Schemas](reference/schemas.md) · [Repositories and queries](reference/data.md) · [The database](reference/database.md) · [Storage](reference/storage.md) |
| Runtime | [Configuration](reference/configuration.md) · [Default settings](reference/defaults.md) · [Cache](reference/cache.md) · [Feature flags](reference/features.md) · [Logging](reference/logging.md) · [CLI](reference/console.md) |
| Background | [Queue and worker](reference/queue.md) · [Scheduler](reference/scheduler.md) · [Mail](reference/mail.md) |
| Security | [Security](reference/security.md) · [Sessions](reference/sessions.md) · [Authentication and authorization](reference/auth.md) · [IP addresses](reference/network.md) |
| Operations | [Performance](reference/performance.md) · [Observability](reference/observability.md) · [System operations](reference/system.md) · [MCP](reference/mcp.md) |

## Running an application

- **[Deployment and security](operations/deployment.md)**: setting up the web
  server, and what it must never serve.
- **[Running in production](operations/running.md)**: the steps for a first
  deployment and for every deployment after it, cron, queue workers, and
  running on several servers.
- **[Updating the framework](operations/updating.md)**: moving an application
  to a newer framework release, and undoing it.
- **[Troubleshooting](troubleshooting.md)**: symptoms, their causes, and fixes.

## Working on the framework itself

- **[Contributing](contributing/README.md)**: the checks every change must pass,
  and the rules framework code follows.
- **[Development](contributing/development.md)**: tooling, and what is built so
  far.
- **[API stability and releases](contributing/releases.md)**: what each stability
  level promises, and how a release is made.

Also in the project root: [`CHANGELOG.md`](../CHANGELOG.md), what changed in
each version, and [`UPGRADING.md`](../UPGRADING.md), what to change in your
application when you upgrade.

## Glossary

The words these pages use, in plain terms, each linked to where it is explained
in full.

| Word | Meaning |
|---|---|
| **Module** | A folder under `modules/` holding one part of your application: its pages, commands, tables and jobs. `modules/Shared/` is the one every application has, loaded first; every other folder with a `module.php` is a module named after the folder, whatever you call it. See [Modules](reference/modules.md). |
| **`module.php`** | The one file that tells the framework what a module adds: its routes, commands, services, hooks and settings. See [Modules](reference/modules.md). |
| **Module id** | A module's name, taken from where its folder is: `modules/Notes/` is `Notes`. |
| **Route** | A rule that connects a URL and an HTTP method, such as `GET /notes`, to the code that answers it. See [Routing](reference/routing.md). |
| **Handler** | The class or method a route calls. It receives the request, and returns a response, a string (HTML) or an array (JSON). See [Routing](reference/routing.md). |
| **Request, Response** | The framework's objects for what the browser sent and what goes back to it. |
| **Template** | A file that produces HTML, written in Twig (`.twig`) or PHP (`.php`). The site's templates live in `templates/`, and a module's in its `Templates/` folder. See [Templates](reference/templates.md). |
| **Twig** | The default template language. It escapes everything it prints, so text from a user cannot become HTML. |
| **Layout** | The template that holds what every page shares, such as the header and footer. `templates/layout.twig` is the default one. |
| **Asset** | A static file for the browser: a stylesheet, script or image. You link to one with `asset()`. See [Assets](reference/assets.md). |
| **Service** | An object your code needs, such as a repository. A module registers its services in `module.php`. |
| **Container** | The part of the framework that builds services and hands them to the classes that ask for them in their constructors. That handing-over is called *injection*. |
| **Model** | A class for one thing your application knows about, such as a note or an invoice, with the rules that keep it valid. It does not save itself. See [Models](reference/models.md). |
| **Repository** | The class that reads models from storage and saves them. You name its methods after what your application does, such as `latest()`. See [Repositories and queries](reference/data.md). |
| **Migration** | A file that creates or changes a database table. `php laika migrate` runs the ones that have not run yet. See [The database](reference/database.md). |
| **Command** | Something you run in a terminal, such as `php laika migrate`. A module can add its own. See [CLI](reference/console.md). |
| **Hook** | A named moment, such as `note.added`, that one module announces and other modules can react to. See [Hooks and filters](reference/hooks-and-filters.md). |
| **Filter** | A named value that other modules may change before it is used, such as a page title. See [Hooks and filters](reference/hooks-and-filters.md). |
| **Configuration** | Settings: the framework's defaults, then a module's defaults, then your files in `config/`, with environment variables where a file reads them. See [Configuration](reference/configuration.md). |
| **`.env`** | An optional file of environment variables for your own machine, such as a database password. It is never committed to git. See [Configuration](reference/configuration.md). |
| **Job, queue, worker** | A job is work to do later, such as sending an email. It waits in a queue until a worker process runs it. See [Queue and worker](reference/queue.md). |
| **Debug mode** | `APP_DEBUG=true`: errors show full details. Only for your own machine, never for a live site. |
