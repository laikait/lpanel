# REST

A REST endpoint here is a handler that returns JSON, reached through the same
router and dispatcher as a web page.

**REST is not a subsystem.** There is no `engine/Rest/`, no API kernel, no
`ApiController`, no Resource class and no Transformer — an architecture test
asserts those folders do not exist. Everything on this page is a convention
layered on top, not machinery underneath.

[JSON APIs](../guides/json-apis.md) builds one from nothing. The examples below
are the showcase `Example` module's customer endpoints (`tests/Fixtures/Showcase/`); a
fresh installation has none of these routes.

## An endpoint

```php
final class CustomerApi
{
    public function show(int $id): JsonResponse
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound();

        return ApiResponse::item(CustomerSchema::resource()->serialize([...]));
    }
}
```

| Factory | Produces |
|---|---|
| `ApiResponse::item($data)` | `{"data": …}` |
| `ApiResponse::collection($rows, $page->meta())` | `{"data": […], "meta": …}` |
| `ApiResponse::created($data, $url)` | 201 with a `Location` header |
| `ApiResponse::noContent()` | 204, no body and no content type |
| `ApiResponse::accepted()` | 202, for work taken but not done |

**Nothing imposes them.** The dispatcher still accepts a plain array, so an
application that wants a different envelope writes one. What these remove is the
fifteenth hand-written `['data' => …]`, not the choice.

`ApiResponse` deliberately does **not** know what a `Page` is. A `page()`
convenience would put the data layer inside the HTTP layer; `$page->meta()` at
the call site costs eleven characters and keeps transport ignorant of storage.
Pagination *links* are built by the handler for the same reason — building one
needs the router and the route's own name.

## One route, two representations

```bash
curl /customers                                 # the page
curl -H 'Accept: application/json' /customers   # the payload
curl /customers.json                            # the payload, for clients that
                                                # cannot set Accept
```

A browser request and a REST request are the same request arriving with
different `Accept` headers, so answering both is a branch rather than an
architecture:

```php
if ($request->negotiate(['text/html', 'application/json']) === 'application/json') {
    return ($this->json)($request);
}
```

Offers go in the server's order of preference, and that order breaks a tie the
client did not break. Anything the endpoint cannot produce is a **406** naming
what it could have returned, rather than JSON the client has no way to read.

**Negotiation is a call, not middleware.** A route knows what it can produce;
the framework does not. There is no configuration for it, because the offers are
a fact about the endpoint, written next to the endpoint.

### Quality values are honoured

```
Accept: application/json;q=0.9, text/html;q=0.8
```

A client that sends this wants JSON. Any check of the form "does the header
contain text/html" hands it a web page, and an API quietly serves markup to a
program.

`MediaType` parses the header properly — quality, specificity, parameters,
`q=0` as an explicit refusal, `+json` suffixes — and `Negotiator` ranks the
offers against it.

One rule worth spelling out: when a client names types and includes neither HTML
nor JSON, error bodies come back as JSON. It is not a browser — a browser would
have said `text/html` — so the machine-readable body is the more useful of the
two wrong answers.

## Requiring a JSON body

```php
$request->requirePayload();   // 415 if the body is not JSON
```

415 and 400 are different failures and are reported differently. Without that
line, a POST whose `Content-Type` is `text/plain` reaches the schema as an empty
payload, and the client is told its fields are missing — which sends whoever is
debugging it to look at the fields instead of at the header.

A vendor type such as `application/vnd.example.v2+json` satisfies a JSON
requirement; refusing it would be pedantry rather than safety.

## One error shape

```json
{
  "error": {
    "status": 400,
    "title": "Bad Request",
    "message": "The customer could not be created.",
    "fields": { "name": ["is required"], "email": ["is required"] },
    "expected": { "name": { "type": "string", "required": true } }
  }
}
```

`status`, `title` and `message` are always present, always first, and never
change meaning; `with()` refuses to overwrite them. Anything else is an addition
under its own key, so a client that ignores unknown keys keeps working.

**Every error in the application is this shape**, because every error goes
through `ErrorDocument`: a handler's validation failure, a 404 from the router,
a 415 from a content-type check, a 500 from an uncaught exception. Before it
existed those were built by different pieces of code that agreed by coincidence,
and an architecture test now fails if a second one appears.

```php
return ErrorDocument::validation(
    fields: $result->messages(),
    message: 'The customer could not be created.',
    expected: $schema->describe()['fields'],
)->toResponse();
```

**Returned rather than thrown**, because the handler has more to say than a
status line. Throwing hands the error to the error handler, which knows the
status and nothing about which fields were wrong.

Outside debug mode an exception's message is replaced wholesale with the status
text rather than filtered — filtering means guessing which substrings are
secret, and that guess is wrong eventually. `HttpException` messages are written
by the framework and survive.

It is deliberately **not** RFC 9457 `problem+json`. That format nests the useful
part next to `type` and `instance` URIs most APIs never populate meaningfully,
and needs a content type tools handle worse.

## Versioning is a prefix, not a subsystem

```php
$routes->group('/api/v1', …, name: 'api.v1.', meta: ['api' => true, 'version' => 'v1']);

$routes->group('/api/v0', …, name: 'api.v0.', meta: [
    'version'    => 'v0',
    'deprecated' => '2026-01-01',
    'sunset'     => '2027-01-01',
]);
```

There is no version negotiator and no version resolver. A URL prefix already
answers "which version" unambiguously, and a second mechanism could only let the
two disagree.

What the metadata adds is somewhere for your own conventions to read from. **The
framework does not send `X-Api-Version`, `Deprecation` or `Sunset` headers
itself** — a filter in your application turns that metadata into them, so a
client learns an endpoint is going away from the response rather than from a
changelog it never read.

`tests/Fixtures/Showcase/Shared/Filters/ApiFilters.php` is a complete example,
and [JSON APIs](../guides/json-apis.md#deprecate-a-version) shows it in place.

Such a filter listens on **`dispatch.response`**, the one point in the lifecycle
where the finished `Response` and the `Route` that produced it both exist. It is
how this framework does cross-cutting response behaviour without middleware: a
filter reads `Route::metadata()` and decorates accordingly, and adding a second
one is a line in `module.php` rather than a place in a pipeline everything has
to pass through.

## Describing the API

`'openapi' => [...]` route meta turns these routes and their schemas into an
OpenAPI 3.1 document: `php laika openapi:export > openapi.json`. See
[OpenAPI](openapi.md).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| An API client gets an HTML page | It did not send `Accept: application/json` | Send the header, or use the `.json` suffix |
| A 406 | The endpoint can offer nothing the client's `Accept` allows | Check the offers passed to `negotiate()` |
| A 415 | The body is not JSON, and `requirePayload()` refused it | Send `Content-Type: application/json` |
| "fields are missing" for a body you sent | The content type was wrong, so the payload was empty | Same fix; that is what `requirePayload()` prevents |
| An error message is just the status text | It was withheld outside debug mode | Read the log, or use `APP_DEBUG=1` locally |
| No `Deprecation` header appears | The framework does not send one | Add the filter; see above |
