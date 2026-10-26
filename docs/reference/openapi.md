# OpenAPI

LPHP describes your JSON API as an [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0)
document, built from what your routes already declare: their paths, their
meta, and the [schemas](schemas.md) their handlers validate with.

```bash
php laika openapi:export > openapi.json
```

Open the file in any OpenAPI viewer (Swagger Editor, Postman, Insomnia, Scalar),
or hand it to a client generator. Nothing is installed and nothing is served
unless you ask for it.

## Describe a route

Add `openapi` meta. `true` describes the route with what it already declares;
an array adds details.

```php
use App\Engine\Schema\Field;

$routes->group('/api/v1', static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->meta(['openapi' => [
        'summary'   => 'A page of customers',
        'query'     => PaginationSchema::schema(),
        'responses' => [200 => ['data' => [CustomerSchema::resource()]]],
    ]]);

    $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
        ->where('id', '\d+')
        ->meta(['openapi' => [
            'summary'   => 'One customer',
            'path'      => ['id' => Field::int('id')->describedAs('The customer id.')],
            'responses' => [200 => ['data' => CustomerSchema::resource()], 404 => 'No such customer'],
        ]]);

    $routes->post('/customers', [CustomerApi::class, 'store'])->meta(['openapi' => [
        'body'      => CustomerSchema::input(),
        'responses' => [201 => ['data' => CustomerSchema::resource()]],
    ]]);

    $routes->get('/debug', DebugDump::class)->meta(['openapi' => false]);   // not this one
}, meta: ['openapi' => true, 'csrf' => false]);
```

**Only routes with `openapi` meta appear.** Pages, the login form and anything
else you did not mark are left out, so the document never lists an endpoint by
accident. `true` on a group describes every route in it; `false` on a route takes
it back out. A route's own array replaces its group's `true`.

| Key | Value | Default |
|---|---|---|
| `summary`, `description` | a string | none |
| `tags` | a list of strings | the owning module |
| `operationId` | a string | the route name, with `_` for `.` |
| `path` | parameter name => `Field` | a string, with the `where()` pattern |
| `query`, `headers` | a `Schema`, one parameter per field | none |
| `body` | a `Schema` | none |
| `body_type` | a media type | `application/json` |
| `responses` | status => response, see below | none |
| `security` | `true` (bearer token) or `false` | `true` when the route has `auth` or `can` |

A mistake, such as an unknown key or a `body` that is not a `Schema`, makes
`openapi:export` exit 1. The message names the route and the key.

## Responses

| Form | Body |
|---|---|
| `200 => Schema` | that schema |
| `200 => [Schema]` | a JSON list of it |
| `200 => ['data' => Schema]` | `{"data": …}`, as `ApiResponse::item()` sends |
| `200 => ['data' => [Schema]]` | `{"data": [ … ], "meta": {…}}`, as `ApiResponse::collection()` sends |
| `404 => 'No such customer'` | none, only a description |
| `201 => ['description' => '…', 'schema' => Schema]` | a description of your own, and `schema` or `data` as above |

Use `'default'` for "any other status".

**The framework adds the answers its guards give before your handler runs**,
all with the [error document](rest.md#one-error-shape)'s shape:

| The route has | Added |
|---|---|
| `auth` or `can` | 401, and bearer security |
| `can` | 403 |
| `rate_limit` | 429 |
| `deprecated` meta | `deprecated: true` |

A response you declare yourself takes precedence over an added one.

**A `body` adds no 400 or 415.** It describes the body; it does not validate it.
If your handler calls `requirePayload()` and validates against the schema,
declare the answers it gives: `400 => 'The body does not match'`,
`415 => 'The body is not JSON'`.

## What it cannot know

- **Responses are never inferred.** No handler runs. A route without `responses`
  lists only what the framework adds.
- **A `check()` closure becomes words.** "Must be an email address." is added to
  the field's description, because JSON Schema cannot run PHP.
- **A `pattern()` is passed on only when it means the same to a JavaScript-style
  validator.** A plain `/…/` or `/…/u` expression is passed on, with `\A` and `\z`
  at the ends as `^` and `$`. Anything only PHP understands becomes its
  expectation text instead: other flags (`i`, `x`), POSIX classes (`[[:alpha:]]`),
  possessive quantifiers (`a++`), atomic groups, inline flags, `\h` or `\x{…}`.
  A wrong pattern would make a client check different input than the server.
- **An optional parameter is two paths.** `/reports/{year?}` is listed as
  `/reports/{year}` and `/reports`, because OpenAPI has no optional path segment.

Schemas are listed under `components.schemas` by `Schema::name()`, so two
different schemas must not share a name, and a name can use only letters,
digits, `.`, `-` and `_`. Rename one with `named()`.

A meta mistake on a route makes `/openapi.json` answer 500. Run
`php laika openapi:export` in CI so it is found before a deploy.

## Switching it on from the console

```bash
php laika openapi:docs --enable          # /docs and /openapi.json
php laika openapi:auth --enable          # both need a login
php laika openapi:auth --enable --can=api.docs   # or a capability
php laika openapi:docs                   # what is on, and who decided
php laika openapi:auth --disable
php laika openapi:docs --disable         # both off
```

`openapi:docs --enable` turns on the [docs page](#the-docs-page) and the
description it reads, at `/docs` and `/openapi.json` unless you give `--path` and
`--spec`. `--disable` turns both off.

- **The switch beats `config/openapi.php`.** Running a command is the more recent
  decision, so it wins for what it sets. Settings it never touched still come
  from config.
- **It is written to `system/Runtime/openapi.json`**, the way `php laika down` is,
  so it applies at once, survives `config:cache`, and holds for **this machine
  only**. Run it on each web server.
- **Production is warned, not stopped.** Turning the docs on with no login in
  production prints a warning naming `openapi:auth --enable`, and `security:check`
  fails until you do.
- **A capability must exist.** `--can` refuses a capability no module declares,
  because it would refuse everybody.
- **`openapi.runtime => false`** in config makes the framework ignore the switches,
  so config is the only source. The test suite does this.

## The document

| Config key | Default | |
|---|---|---|
| `openapi.title` | `null` | `app.name` when the application sets one, else `API` |
| `openapi.version` | `'1.0.0'` | your API's version, not the framework's |
| `openapi.description` | `''` | |
| `openapi.route` | `null` | serve it over HTTP too, see below |
| `openapi.docs` | `null` | a Swagger UI page over it, see below |
| `openapi.meta` | `[]` | route meta for that endpoint |
| `openapi.runtime` | `true` | `false` ignores what the console switched |

`servers` is `APP_URL`, or the base path when it is not set. It is never the
`Host` header. The same routes give the same bytes, so the file can be
committed and diffed.

## Serving it over HTTP

```php
// config/openapi.php
return [
    'route' => '/openapi.json',
    'meta'  => ['auth' => true],          // or ['can' => 'api.docs']
];
```

The endpoint is an ordinary route, so `auth`, `can` and `rate_limit` in
`openapi.meta` are enforced like on any other route. It sends an `ETag`, and a
request that sends the same ETag back gets a 304.

**Decide who may read it.** The document is a map of your API. `security:check`
fails in production when `openapi.route` is set without `auth` or `can`. If only
developers need the file, leave `openapi.route` unset and use the command.

If a route of yours already answers that path, boot fails and names it.

## The docs page

```php
// config/openapi.php
return [
    'route' => '/openapi.json',
    'docs'  => '/docs',
    'meta'  => ['auth' => true],
];
```

`/docs` is [Swagger UI](https://github.com/swagger-api/swagger-ui) over the
description at `openapi.route`, which must be set too. You can browse every
operation, see its schemas, and use **Try it out** against this application.

- **The same access rule.** `openapi.meta` guards the page, its files and the
  description alike. `security:check` treats the page like the description.
- **Nothing from anywhere else.** Swagger UI is part of the framework, at a
  pinned version (5.33.1, Apache-2.0, with its `LICENSE` and `NOTICE` in
  `engine/OpenApi/ui/`), and is served by these routes. The page works offline
  and under the default Content-Security-Policy, and it does not send the
  description to Swagger's public validator.
- **Tokens stay in the tab.** A token typed into **Authorize** is not saved
  between visits, and the server never sees it except on the requests you send.
- **Try it out** sends real requests to `servers`, which is `APP_URL` or this
  application. Routes that check CSRF answer it 403, because a viewer sends no
  CSRF token. That is why bearer-token routes have `'csrf' => false`.

The files are cached for a year under a URL that carries the version, so a
framework update that changes Swagger UI is picked up straight away.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| `"paths": {}` | No route has `openapi` meta | Add `'openapi' => true` to the route or its group |
| A route is missing | It is a `HEAD` route, or it or its group says `false` | Check `route:list` and the meta |
| `Two different schemas are both named …` | Two schemas share a `Schema::name()` | Rename one with `named()` |
| `… cannot name a schema in an API description` | The name has a space, `/` or other character OpenAPI refuses | Rename it with `named()` |
| A field has no `pattern` but its `pattern()` is set | The expression uses PHP-only syntax | Its expectation is in the description instead; see above |
| Boot fails: `openapi.route and openapi.docs both need …` | The two settings name the same path | Give them different paths |
| `Two documented routes answer GET …` | The same method and path twice, often `/x` and `/x/` | Remove one |
| Swagger UI's "Try it out" gets 403 | The route checks CSRF, and a viewer sends no token | Bearer-token routes should have `'csrf' => false`; see [Security](security.md) |
| `/openapi.json` is 404 | `openapi.route` is not set | Set it, with an access rule |
| Boot fails: `openapi.docs … openapi.route is not set` | The page needs a description to show | Set `openapi.route` too |
| `/docs` is blank | A browser extension or a proxy blocked the script, or a policy of your own leaves out `script-src 'self'` | Check the browser console |
