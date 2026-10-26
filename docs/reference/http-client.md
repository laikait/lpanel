# HTTP client

`Http\Client\Client` sends requests to other services: a payment API, a
webhook, a file to fetch. Ask for it in a constructor like anything else.

```php
use App\Engine\Http\Client\Client;

public function __construct(private readonly Client $http) {}

$charge = $this->http
    ->withToken($apiKey)
    ->timeout(10)
    ->retry(2)
    ->post('https://api.example.com/charges', ['amount' => 500])   // an array is sent as JSON
    ->throw()                                                       // 4xx/5xx become an exception
    ->json('id');
```

## Building a request

| | |
|---|---|
| `get($url, $query)`, `head(…)` | `$query` is appended as `?a=1&b=2` |
| `post($url, $body)`, `put`, `patch`, `delete` | an array body is JSON; a string is sent as it is |
| `send($method, $url, $body)` | any other method |
| `baseUrl('https://api.example.com/v2')` | relative URLs are resolved against it |
| `withHeaders([...])`, `withToken($t)`, `accept($type)` | `withToken` sends `Authorization: Bearer …` |
| `asForm()` | array bodies as `application/x-www-form-urlencoded` |
| `timeout($seconds)` | 30 by default (`http.client.timeout`) |

**The client is immutable.** Every one of these returns a new client, so one
configured in a constructor can be shared, and a token added for one call never
reaches another. A header value containing a line break is refused, so input
cannot add headers of its own.

## Reading the answer

```php
$response->status();          // 200
$response->successful();      // 2xx; also clientError(), serverError(), failed()
$response->json('data.id');   // decoded JSON, or one value by a dotted path; null if absent
$response->header('ETag');
$response->body();
$response->throw();           // HttpClientException on 4xx/5xx, carrying the response; itself otherwise
```

**An error status is an answer, not an exception.** A 404 comes back as a
response with `status() === 404`; only `throw()` turns it into one.
`HttpClientException` is for no answer at all — no connection, a timeout — and
for requests refused before they leave. Its message names the method, host and
path, never the query string, where tokens go.

## Redirects

Followed by the client, up to five. A 301, 302 or 303 turns the request into a
GET without a body, as browsers do; 307 and 308 keep the method and body.
**`Authorization` and `Cookie` are dropped when a redirect leaves the host**, so a
token for an API never reaches the CDN it redirects downloads to.

## Retries

```php
$http->retry(3, 200)->get($url);              // up to 3 more tries, waiting 200, 400, 800 ms
$http->retry(2, always: true)->post($url, …); // only when sending it twice is harmless
```

Retried: no connection, a timeout, 429 and 5xx. Not retried: any other 4xx,
which will not change by asking again. A `Retry-After` header is honoured, up to
a minute. **POST and PATCH are not retried unless you pass `always: true`** — a
POST that timed out may already have charged the card.

## URLs somebody else gave you

```php
$http->publicOnly()->post($webhookUrl, $payload);
```

A webhook address, an avatar URL, anything a user typed: `publicOnly()` resolves
the host and refuses the request if **any** of its addresses is private,
loopback, link-local or reserved (see [IP addresses](network.md)), and checks
every redirect the same way. Without it, a user could point your server at its
own database port or at a cloud provider's metadata endpoint.

The request then **connects to the address that was checked**, not to whatever
the host resolves to a moment later: a host that answers with a public address
for the check and a private one for the connection (DNS rebinding) gets
nowhere. TLS still verifies the certificate against the host name. A URL with a
user part or a backslash (`https://a.example\@127.0.0.1/`) is refused, because
that is where PHP and curl disagree about which host it names.

## Transports

`curl` when the extension is loaded, PHP's own stream wrapper otherwise; both
verify TLS certificates. Neither follows redirects or retries itself — the
client does both, the same way over each.

## Testing

```php
use App\Engine\Http\Client\FakeTransport;

$fake = (new FakeTransport())->push(201, ['id' => 7])->push(503);
$client = new Client($fake);

// ... exercise the code ...

$fake->sent()[0]->url;           // what was sent
$fake->sent()[0]->header('Authorization');
```

Bind it in a test with
`$app->container()->instance(Client::class, new Client($fake))`. A queued
`HttpClientException` simulates a request that got no answer; a closure answers
based on the request.

## Extension points

| | |
|---|---|
| `http.client.request` (filter) | the `ClientRequest` about to be sent; return a changed copy to add a header or rewrite the URL |
| `http.client.sent` (hook) | `['method', 'host', 'status', 'ms', 'attempt']` after each attempt; `status` is `null` when there was no answer |

The hook is never given the query string or a header value.

## Settings

| Key | Default | |
|---|---|---|
| `http.client.timeout` | `30` | seconds, for a client that does not set its own |
| `http.client.user_agent` | `LPHP/<version>` | |

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| "is not a public address" | `publicOnly()` and the host resolves privately | Expected for internal hosts; drop `publicOnly()` only for URLs you wrote yourself |
| "did not answer within … seconds" | the timeout | `->timeout(…)`, or `->retry(…)` |
| A 403 or 407 from a proxy | an egress proxy in front of the machine | Its configuration, not the client's |
| A POST is not retried | deliberate | `retry(…, always: true)` if sending it twice is safe |
