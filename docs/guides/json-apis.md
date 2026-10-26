# JSON APIs

This guide shows how to build endpoints that other programs call and that answer
in JSON:

1. group API routes under a version, such as `/api/v1`,
2. answer with one record, or with a list,
3. accept and validate JSON sent to you,
4. page through long lists,
5. report errors in one consistent shape,
6. retire an old version without surprising anyone.

There is no separate API framework. An API endpoint is a handler that returns
JSON, on the same router as your pages. What follows are conventions that keep
an API predictable. The reasons behind them are in [REST](../reference/rest.md).

The examples come from the showcase plugin, `tests/Fixtures/Showcase/Plugins/Example/`,
which the test suite runs, and from a `Desk` module with a `messages` inbox (see
[Storing data](storing-data.md)).

## Step 1: group the routes under a version

In `module.php`:

```php
$routes->group('/api/v1', static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->name('customers.index');

    $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
        ->where('id', '\d+')
        ->name('customers.show');

    $routes->post('/customers', [CustomerApi::class, 'store'])->name('customers.store');
}, name: 'api.v1.', meta: [
    'api' => true,
    'version' => 'v1',
    'rate_limit' => '60/1m',
]);
```

This creates three routes:

| Method and URL | Handler | Route name |
|---|---|---|
| `GET /api/v1/customers` | `ListCustomers` | `api.v1.customers.index` |
| `GET /api/v1/customers/{id}` | `CustomerApi::show()` | `api.v1.customers.show` |
| `POST /api/v1/customers` | `CustomerApi::store()` | `api.v1.customers.store` |

What each part does:

- **`/api/v1`** is the version. The version is always in the URL, so there is
  never any doubt which version a client called.
- **`name: 'api.v1.'`** is put in front of every route name. Build a URL from a
  name with `$router->url('api.v1.customers.show', ['id' => 7])`.
- **`{id}`** is a part of the URL that varies. `where('id', '\d+')` accepts digits
  only. Its value is passed to the handler's parameter named `$id`. If that
  parameter is declared `int` and the value is not a whole number, the client
  gets `400` before your code runs.
- **`'rate_limit' => '60/1m'`** allows 60 requests a minute from one IP address,
  on each route. Other formats: `5/15m`, `1000/1h`, `10/1d`. Past the limit the
  client gets `429` with a `Retry-After` header.
- **`'version' => 'v1'`** is a label for your own code to read, for example to
  add a version header (see [Deprecate a version](#deprecate-a-version)). The framework itself
  does nothing with it.

> **CSRF applies to API routes too.** A browser that is logged in with a session
> cookie can be tricked into sending a `POST`; that is what CSRF protection stops.
> Add `'csrf' => false` to the group **only** if clients log in with a token and
> never with the session cookie: on such a group the session cookie identifies
> nobody, so a page of your own calling it with the session gets `401`. See
> [API clients: tokens](users-and-permissions.md#api-clients-tokens).

## Step 2: answer with one record

```php
public function show(int $id): JsonResponse
{
    $customer = $this->customers->find($id)
        ?? throw HttpException::notFound(\sprintf('/api/v1/customers/%d', $id));

    return ApiResponse::item(CustomerSchema::resource()->serialize([
        'id' => $customer->identity(),
        'name' => $customer->name(),
        'email' => $customer->email(),
    ]));
}
```

- If there is no such customer, `HttpException::notFound()` answers `404`.
- **`ApiResponse::item()`** answers `200` with `{"data": ...}`.
- **`serialize()`** passes the fields through a **schema**, the same declaration
  that describes what the API returns. Choose each field on purpose: never return
  a model as it is, or a field you add to the model later would leak out.

`ApiResponse` has one method per kind of answer:

| Call | Answers |
|---|---|
| `ApiResponse::item($data)` | `200`, `{"data": ...}` |
| `ApiResponse::collection($items, $meta)` | `200`, `{"data": [...], "meta": ...}` |
| `ApiResponse::created($data, $url)` | `201`, with a `Location` header pointing at the new record |
| `ApiResponse::noContent()` | `204`, no body |
| `ApiResponse::accepted($data)` | `202`, the work was accepted but is not finished |

You don't have to use them: a handler that returns a plain array also answers
`200` with that array as JSON.

## Step 3: accept a new record

```php
public function store(Request $request): JsonResponse
{
    $request->requirePayload();

    $payload = $request->json();
    $schema = CustomerSchema::input();
    $result = $schema->validate(\is_array($payload) ? $payload : []);

    if (!$result->isValid()) {
        return ErrorDocument::validation(
            fields: $result->messages(),
            message: 'The customer could not be created.',
            expected: $schema->describe()['fields'],
        )->toResponse();
    }

    /** @var array<string, mixed> $payload */
    $customer = $this->customers->register($schema->deserialize($payload));
    $id = $customer->identity() ?? throw new \LogicException('A stored customer has no id.');

    return ApiResponse::created(
        CustomerSchema::resource()->serialize(['id' => $id, 'name' => $customer->name(), 'email' => $customer->email()]),
        $this->router->url('api.v1.customers.show', ['id' => $id]),
    );
}
```

Step by step:

1. **`requirePayload()`** answers `415` if the body is not JSON. Without it, a
   client that forgot the `Content-Type: application/json` header would be told
   that its fields are missing, and would look for the problem in the wrong place.
2. **`$request->json()`** reads the JSON body as a PHP array.
3. **`validate()`** checks the data against the schema and collects **every**
   problem at once, each with its field, such as `address.postcode`.
4. **If anything is wrong**, `ErrorDocument::validation()` answers `400` with the
   list of problems and a description of the fields that were expected. It is
   returned, not thrown, because this is the code that knows which fields failed.
5. **`deserialize()`** converts values to the right types, fills in defaults, and
   **drops fields the schema does not list**. A client cannot make itself an
   admin by adding `"is_admin": true` to the body.
6. **`ApiResponse::created()`** answers `201` with the new record, and the URL
   where it can be read.

Schemas are explained in [Schemas](../reference/schemas.md).

## Step 4: lists and pages

A list endpoint returns one **page** of records at a time, plus information about
the other pages:

```php
final class MessagesApi
{
    public function __construct(private readonly MessageRepository $messages) {}

    public function index(Request $request): JsonResponse
    {
        $page = \max(1, (int) $request->query('page', 1));
        $inbox = $this->messages->inbox($page);

        return ApiResponse::collection($inbox->items(), $inbox->meta());
    }
}
```

- `$request->query('page', 1)` reads `?page=` from the URL, or `1`.
- `inbox()` is a repository method that returns one `Page`:

```php
public function inbox(int $page, int $perPage = 20): Page
{
    return $this->query()
        ->whereIs('spam', false)
        ->orderByDesc('id')
        ->pageInto(MessageSummary::class, $page, $perPage);
}
```

`MessageSummary` is a [read model](storing-data.md#read-only-what-a-screen-needs):
a small class with just the fields the list shows, and it becomes JSON as exactly
those fields. `meta()` gives the totals for the `"meta"` part of the answer.

If you want links to the next and previous page, build them in the handler with
`$router->url()`. `ApiResponse` knows nothing about pages, on purpose.

**For a list that can grow large, use a cursor instead of page numbers.**
`page()` reads past every earlier row to reach page *n* and counts the whole
table each time, so deep pages get slow. `cursorInto(MessageSummary::class, 20,
$request->query('cursor'))` costs the same on every page and hands back
`nextCursor()` and `previousCursor()` for the client to send as `?cursor=`. See
[Two ways to paginate](../reference/data.md#two-ways-to-paginate).

## Step 5: errors

Every error has the same JSON shape, whether your code produced it or the
framework did:

```json
{"error": {"status": 404, "title": "Not Found", "message": "…"}}
```

- **To answer with an error status, throw `HttpException`:**
  `HttpException::notFound()`, `badRequest()`, `unauthorized()`, `forbidden()`,
  `tooManyRequests()`, or `new HttpException(409, 'Already paid.')`. Its message
  is shown to the client.
- **Any other exception becomes `500`**, and its message is **replaced** with
  "Internal Server Error", unless debug mode is on. So never count on an
  exception's message reaching the client: it might contain something secret.
- A client that sends `Accept: application/json` gets this JSON shape for every
  error, including a URL that does not exist or a wrong HTTP method.

See [One error shape](../reference/rest.md#one-error-shape).

## One URL, HTML or JSON

A page and its data can share one route. The client's `Accept` header decides:

```php
if ($request->negotiate(['text/html', 'application/json']) === 'application/json') {
    return ApiResponse::collection($rows);
}
```

`negotiate()` picks the best match from what the client accepts, including
preferences such as `Accept: application/json;q=0.9, text/html;q=0.8` (JSON
preferred). If it can offer nothing the client accepts, it answers `406`.

## Deprecate a version

When you replace an old version, keep it answering for a while, and tell clients
in every response when it will stop. Mark the old group with dates:

```php
$routes->group('/api/v0', static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->name('customers.index');
}, name: 'api.v0.', meta: [
    'api' => true,
    'version' => 'v0',
    'deprecated' => '2026-01-01',
    'sunset' => '2027-01-01',
]);
```

These `meta` values are only labels. To turn them into response headers, add two
**filters** to your module. A filter on `dispatch.response` sees each finished
response together with the route that produced it:

```php
final class ApiFilters
{
    public static function stampVersion(Response $response, Route $route): Response
    {
        $version = $route->metadata()['version'] ?? null;

        return \is_string($version) ? $response->withHeader('X-Api-Version', $version) : $response;
    }

    public static function announceDeprecation(Response $response, Route $route): Response
    {
        $metadata = $route->metadata();
        $deprecated = $metadata['deprecated'] ?? null;

        if (!\is_string($deprecated)) {
            return $response;
        }

        $response = $response->withHeader('Deprecation', self::httpDate($deprecated));
        $sunset = $metadata['sunset'] ?? null;

        return \is_string($sunset) ? $response->withHeader('Sunset', self::httpDate($sunset)) : $response;
    }

    /** A date the specifications recognise, from a date a developer can type. */
    private static function httpDate(string $date): string
    {
        $timestamp = \strtotime($date);

        return $timestamp === false ? $date : \gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }
}
```

```php
// in module.php
$module->filter('dispatch.response', [ApiFilters::class, 'stampVersion'], priority: 20);
$module->filter('dispatch.response', [ApiFilters::class, 'announceDeprecation'], priority: 30);
```

Now every response from `/api/v0` carries:

- `X-Api-Version: v0`, saying which version answered,
- `Deprecation: ...` (RFC 9745), saying since when it is deprecated,
- `Sunset: Fri, 01 Jan 2027 00:00:00 GMT` (RFC 8594), saying when it stops.

Clients see the warning in the responses they already get, which reaches far more
people than a note in your changelog. This is the showcase's own code, from
`tests/Fixtures/Showcase/Shared/Filters/ApiFilters.php`, and its tests check the
headers.

## Try it

With `composer serve` running and your routes in place:

```bash
curl -i http://127.0.0.1:8080/api/v1/customers/1
curl -i -X POST -H 'Content-Type: application/json' \
     -d '{"name":"Ada","email":"ada@example.test"}' \
     http://127.0.0.1:8080/api/v1/customers
curl -i -X POST -H 'Content-Type: text/plain' -d 'x' http://127.0.0.1:8080/api/v1/customers   # 415
php laika route:list
```

`curl -i` prints the status and headers too, so you can see `201`, `Location` or
`415` for yourself.

To hand the API to someone else, add `openapi` meta to the routes and run
`php laika openapi:export > openapi.json`. The file opens in Swagger Editor,
Postman or any other OpenAPI tool. See [OpenAPI](../reference/openapi.md).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| `403` on every `POST` | CSRF protection: the request has no token. | Send the `X-CSRF-TOKEN` header, or, for token-only APIs, add `'csrf' => false` to the group. |
| `415 Unsupported Media Type` | The body was not sent as JSON. | Send `Content-Type: application/json`. |
| A `500` with "Internal Server Error" and no detail | An exception other than `HttpException` was thrown, and debug mode is off. | Look in the log, or turn on `APP_DEBUG` on your own machine. |
| `404` for `/api/v1/customers/abc` | `where('id', '\d+')` accepts digits only. | That is intended; send a number. |
| No `X-Api-Version` or `Sunset` header | The framework does not add them by itself. | Add the filters from [Deprecate a version](#deprecate-a-version). |
| `429 Too Many Requests` | The rate limit was reached. | Wait for the `Retry-After` seconds, or raise the limit. |

More in [Troubleshooting](../troubleshooting.md).
