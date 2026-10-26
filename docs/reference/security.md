# Security

Some protections are on for every request without you doing anything. Others
are decisions only your application can make. This page separates the two, then
explains each.

**On by default, everywhere:**

| Protection | Refuses |
|---|---|
| CSRF | a form post that did not come from your own page |
| Request size limit | a body larger than `security.max_request_bytes`, with 413 |
| Security headers | framing, content sniffing, referrer leakage, camera/microphone/location access |
| Content-Security-Policy | an injected script on an HTML page (while debug is off) |
| Login throttle | password guessing, one account or many ([Authentication](auth.md#logging-in)) |
| Escaping | HTML in a template that came from a user |

**Yours to decide:**

| Decision | Where |
|---|---|
| Rate limits | `meta(['rate_limit' => '60/1m'])` on a route |
| What an upload may be | `UploadPolicy`, at the endpoint that takes one |
| Content-Security-Policy | on HTML pages while debug is off, unless configured; see [Headers](#headers) |
| HSTS | off by default, HTTPS only; see [Headers](#headers) |
| `APP_KEY` | set one |

Check what a deployment actually has:

```bash
php laika security:check      # audit this deployment; exits 1 on a problem
php laika security:key        # print a new APP_KEY
```

`security:check` is the command this page is really about. A security setting is
invisible when it is working and invisible when it is not, so "is HSTS on", "is
there a key", "is anything web-readable that should not be" get checked once
during setup and never again. It reports what the running process **actually
resolved to**, rather than what a config file says, and exits non-zero, so it
can be a deployment step rather than something somebody remembers.

## CSRF is opt-out

Every `POST`, `PUT`, `PATCH` and `DELETE` is checked unless its route opts out:

```php
$routes->group('/api/v1', ..., meta: ['csrf' => false, 'rate_limit' => '60/1m']);
```

**The direction is the whole decision.** Opt-in means the route somebody adds in
a hurry is unprotected, and that is reliably the one that matters.

Opting out is for an API authenticated by a **bearer token**, which has no
ambient credential to abuse: the token has to be put on the request by whoever
makes it, and another site cannot do that.

Note what does *not* imply it. An API authenticated by a **session cookie**
needs CSRF exactly as much as a form does, which is why `'api' => true` grants
nothing and the exemption is written out by hand, one group at a time.
`security:check` lists every route that took it.

**And the framework holds you to it.** On a route with `'csrf' => false` the
session cookie identifies nobody: only a bearer token does. With the check off,
a session would let any site in the world post to that route as whoever is
logged in, because the browser attaches the cookie on its own. A login made
during such a request (the social sign-in callback is one) still counts. With
CSRF switched off everywhere (`security.csrf.enabled`), this does not apply.

### What is checked

Two independent checks, either of which fails the request:

1. **A token** in a cookie, echoed back in `_token` or `X-CSRF-TOKEN`. The
   same-origin policy stops another site reading the cookie, so it cannot echo
   it.
2. **The `Origin` header**, which browsers send on unsafe cross-origin requests
   and script cannot forge.

**Over HTTPS the cookie is `__Host-XSRF-TOKEN`.** A token is valid for whoever
fetched it, so without that a sibling subdomain, or anyone able to set a cookie
over plain HTTP, could plant a token they were issued themselves and post the
matching field. A browser accepts a `__Host-` cookie only from a secure response
of this exact host, with `Path=/` and no `Domain`, which neither of them can
send, and over HTTPS only that name is read. Plain HTTP keeps `XSRF-TOKEN`. A
script that echoes the cookie reads `__Host-XSRF-TOKEN` on HTTPS (for axios,
`xsrfCookieName`); `security.csrf.host_prefix = false` keeps the old name
everywhere and gives this protection up.

Origin is checked only when present. Absent means "cannot tell", because proxies
and privacy tools strip it, and treating that as an attack produces false
rejections. `Referer` is not checked at all, for the same reason more so.

`check()` returns **a reason, not a bool**. "CSRF token mismatch" is one of the
least useful errors a framework produces: the form is missing a field, cookies
are being dropped, the page is older than the cookie, or the request really is
cross-site — four completely different fixes behind one message.

An existing valid token is reused rather than rotated, because two tabs open on
the same site is ordinary browsing, and rotating would show the second one a
security error for it.

### What this does not do

- **It does not survive XSS.** Script on your own page can read the cookie just
  as your own page can.
- **It is not a replacement for SameSite cookies**, but the layer underneath
  them.
- **A token is bound to a browser, not to a login.** Sessions close half of
  that: regenerating the session id **rotates the token**, and logging in
  regenerates the session. Binding a token to one particular session, so one
  user's token cannot be presented by another, is **not built** — see
  [What is not built](../what-is-not-built.md).

## APP_KEY

```bash
APP_KEY=$(php laika security:key --bare)
```

With a key, CSRF tokens are signed, so a cookie this application did not issue
is refused. A signature says who issued a token, not to whom: what stops a
planted cookie is the `__Host-` name above.

Without one, plain double-submit still works and still refuses cross-site
requests; what is lost is that one guarantee. **The framework says which mode it
is in** rather than implying the stronger one, and an application boots either
way.

A missing key costs more than CSRF signing, and none of it is visible, which is
why **`security:check` fails a production deployment without one**:

- cached values and queued jobs are no longer sealed, so whoever can write the
  cache directory, the cache table or the queue decides what is `unserialize()`d;
- `Encrypter`, encrypted cookies and social sign-in refuse to run.

`security:key` **prints and does not write.** Every other framework's equivalent
edits `.env`, and that convenience is exactly wrong here: silently replacing a
live key invalidates every token and session the application has issued. A
command that cannot do that by accident is worth one copy and paste.

`Signer` is the only place an HMAC is computed, and an architecture test keeps it
that way. A second implementation is a second chance to compare the result with
`===`, which returns as soon as two bytes differ and so leaks how many leading
bytes were right. Signatures are bound to a **purpose**, so a CSRF token does
not verify as a signed URL.

## Encryption

```php
public function __construct(private readonly Encrypter $encrypter) {}

$stored = $this->encrypter->encrypt($apiToken, 'billing.api-token');   // "v1.…"
$token  = $this->encrypter->decrypt($stored, 'billing.api-token');      // the value, or null
```

`Signer` proves a value was not changed but leaves it readable; `Encrypter` hides
it as well. Use it for what you must store but nobody should read — a third-party
API token in the database, a value in a cookie. For passwords use
`Password::hash()`, which is one-way; an encrypted password can be decrypted.

- **libsodium XChaCha20-Poly1305**, with a fresh random nonce every time, so two
  encryptions of one value never look alike. A changed, truncated or forged token
  fails to decrypt rather than decrypting to garbage.
- **The second argument is a purpose**, bound into the token. A value encrypted
  for `billing.api-token` returns `null` if decrypted as anything else, so it
  cannot be copied into another column where it would mean something else.
- **The key comes from `APP_KEY`**, through HKDF, so encryption and signing never
  share a key. Without `APP_KEY`, `encrypt()` throws: silently storing plaintext
  would be worse.
- **`decrypt()` returns `null` for anything wrong**, like `Signer::verify()`.

**Encrypted cookies and model attributes** use the same thing with the purpose
chosen for you:

```php
$response->withCookie(Cookie::encrypted($this->encrypter, 'cart', $json, \time() + 86400));
$json = $request->decryptedCookie($this->encrypter, 'cart');   // null when missing or changed
```

The cookie's name is its purpose, so a value cannot be moved into another
cookie. The browser can still delete the cookie or replay an older one; put an
expiry inside the value when that matters. For a model, mark the property
`#[Encrypted]`; see [Models](models.md#encrypted-attributes).

**Rotating `APP_KEY`:** move the old key into `APP_PREVIOUS_KEYS`, set a new
`APP_KEY`, re-encrypt what you stored (decrypt, then encrypt again — the new
token uses the new key), then remove the old key. Previous keys only ever
decrypt. `security:check` warns while one is set. Note that a new `APP_KEY` still
invalidates every signed CSRF token, as above.

## Receiving webhooks

```php
// config/security.php
return ['webhooks' => [
    'stripe' => ['format' => 'stripe', 'secret' => Env::string('STRIPE_WEBHOOK_SECRET')],
    'github' => ['format' => 'github', 'secret' => Env::string('GITHUB_WEBHOOK_SECRET')],
]];

// a module's routes: no CSRF token comes with a webhook
$routes->post('/webhooks/stripe', StripeWebhook::class)->meta(['csrf' => false]);

// the handler
public function __invoke(Request $request, Webhooks $webhooks, Queue $queue): Response
{
    $event = $webhooks->receive('stripe', $request);   // 400 unless Stripe signed it

    if (!$event->duplicate) {
        $queue->push(new HandleStripeEvent($event->payload));
    }

    return new Response('', 200);
}
```

`receive()` checks the signature and returns a `Webhook`: `id`, `type`,
`payload` (the JSON body decoded), the raw `body`, and `duplicate`. The work
belongs in a job. Answer quickly: senders time out after a few seconds and send
again.

| `format` | Checks | `id` / `type` from |
|---|---|---|
| `stripe` | `Stripe-Signature`: HMAC-SHA256 of `t.body`; the timestamp within `tolerance` | the event's `id` / `type` |
| `github` | `X-Hub-Signature-256: sha256=…` | `X-GitHub-Delivery` / `X-GitHub-Event` |
| `shopify` | `X-Shopify-Hmac-Sha256`, base64 | `X-Shopify-Webhook-Id` / `X-Shopify-Topic` |
| `standard` | [Standard Webhooks](https://www.standardwebhooks.com/): `webhook-signature` over `id.timestamp.body`, a `whsec_` secret; the timestamp within `tolerance`. Svix, Resend, Clerk and others send this | `webhook-id` / the body's `type` |
| `hmac` | a header holding an HMAC of the body. Set `header` (`X-Signature`), `algorithm` (`sha256`), `encoding` (`hex` or `base64`), `prefix` (such as `sha256=`), and optionally `id_header` and `type_header` | those headers |

- **The signature is over the raw body**, which is why this reads
  `Request::body()`. It is computed by `Signer::hmac()` and compared in constant
  time.
- **`secret` may be a list**, so you can rotate secrets: any one of them is
  accepted.
- **Replays are refused.** Where the format signs a timestamp, a delivery more
  than `tolerance` seconds old or early (default 300) is a 400. Every id is also
  remembered for `remember` seconds (default a day) through the cache's atomic
  `add()`, and a second delivery of it comes back with `duplicate` true. Answer
  it 200 and do nothing. That needs a store every process shares: the `file` or
  `database` cache, or, with the default `array` cache (which forgets when the
  request ends), a file store of its own in `system/Cache/webhooks/`.
- **If queuing fails after `receive()`**, call `$webhooks->release($event)` and
  answer 500. The sender's retry is then processed instead of being treated as
  a duplicate.

## Secrets

```php
$key = new Secret($raw);

echo $key;                      // [redacted]
var_dump($key);                 // [redacted]
json_encode(['key' => $key]);   // {"key":"[redacted]"}
serialize($key);                // throws
$key->reveal();                 // the actual bytes
```

The problem is not storage; it is the moment after. A key in a plain string is
one `var_dump($config)` from a screenshot in a ticket, one `"bad key: $key"`
from a log aggregator, one `json_encode($settings)` from a debug endpoint. None
of those is a decision anybody made.

**`reveal()` is the only way out, and that is the design.** Every place that
needs the value says so in one conspicuous word, so `grep -rn 'reveal()'` is a
complete list of where secrets are used. An architecture test refuses a second
accessor.

Serialising throws, because a secret inside a queued job or a cached value is a
secret written somewhere it was never meant to be — usually because a closure
captured it.

## Rate limiting

```php
meta(['rate_limit' => '60/1m'])   // also 5/15m, 1000/1h, 10/1d
```

**The key is your decision.** Per IP protects against one machine; per username
protects one account from every machine; per token protects a quota. Those are
different threats, and a framework that picked one would be wrong for the other
two.

What the framework supplies is the place to put the answer, and a sensible
default scoping: route plus client, so a limit on the login form does not also
stop the same office reading the catalogue.

**Counts live in `CounterStore`, not in the cache**, and the distinction is not
pedantry:

- A cache is allowed to forget — that is its contract — and a store that may
  drop an entry is a limiter that may forget how many login attempts have been
  made.
- A counter must be incremented **atomically**, and `get`/`set` cannot do that:
  two requests arriving together both read 5, both write 6, and the limit is off
  by exactly as much as the traffic it exists to stop.

The file store holds a `flock()` across the read and the write, which is the one
place in this framework that needed a lock rather than a single atomic system
call.

**Memory counters are no limit at all** — a web request is a process that ends —
and `security:check` calls that configuration a failure rather than a warning.

The window is **fixed, not sliding**, and the consequence is real: a client can
spend its whole allowance at the end of one window and the whole of the next at
the start. A sliding log stores every timestamp, and a sliding counter needs two
windows read atomically; for "five login attempts" the boundary burst is not
what matters, and claiming a precision this does not have would be worse.

A refused attempt is **still counted**, or a client that keeps hammering would
start fresh the instant the window ends — rewarding the behaviour the limit
exists to discourage. `RateLimit::headers()` returns `RateLimit-*` and, only on
a refusal, `Retry-After`: sending it on an allowed request has been known to
make well-behaved clients wait.

**"The client" is `Request::ip()`**, and behind a load balancer or CDN that is
only right when the proxy is listed in `http.trusted_proxies` — otherwise every
visitor is the proxy and shares one bucket. Entries may be CIDR blocks, and
`security:check` fails on one it cannot read. See
[IP addresses](network.md#the-client-address-behind-a-proxy).

## Uploads

```php
$problems = UploadPolicy::images()->check($request->file('avatar'));

if ($problems === []) {
    $path = UploadPolicy::images()->store($request->file('avatar'), $directory);
}
```

**Nothing is automatic.** A framework cannot know that this endpoint takes
avatars and that one takes CSVs, so a global policy is either wrong for one of
them or not a policy.

`check()` returns **every** problem rather than the first, because a user who
fixes the size and is then told about the type has uploaded twice for one answer
the server already had.

`store()` and `storeOn()` run `check()` themselves and throw
`SecurityException` when it finds anything, so skipping the check cannot put an
unchecked file on disk. `checkAll($request->files()['photos'], maxFiles: 10)`
checks a multi-file field and names the file behind each problem. Show a
client's name with `UploadedFile::displayName()`, which drops control and
right-to-left override characters; never store anything under it.

Five rules the policy enforces:

- **An allowlist, never a blocklist.** A blocklist has to enumerate `.php`,
  `.phtml`, `.phar`, `.htaccess` and whatever the next server module adds.
- **Exactly one extension**, because `avatar.php.jpg` is a `.jpg` to an
  extension check and a script to an Apache with an old `AddHandler` line.
- **Contents checked against the name**, with `finfo`, since the client's
  `Content-Type` is whatever the client said.
- **Images must decode** (`getimagesize`) and stay under 25 megapixels
  (`maxPixels`), so a tiny PNG that inflates to gigabytes is refused. Where
  `fileinfo` is missing, the content check fails instead of passing.
- **The stored name is generated**, never the client's. Every rule for making an
  attacker-controlled name safe is a rule that can be got subtly wrong.

Path stripping happens one layer down, in `UploadedFile::clientName()`, and
`UploadPolicy` deliberately does not repeat it. A check that can never fire is
worse than none, because it reads as though this class were what stands between
`../../etc/passwd` and the filesystem.

## Request size, and the failure that looks like a bug

Bodies over `security.max_request_bytes` are refused with 413 before anything
parses them. Unbounded bodies are how one client exhausts a server's memory.
The body is read no further than one byte past the limit, so this holds for a
chunked request too, which carries no `Content-Length` to judge it by.

The second check is the one worth having. **When an upload exceeds
`post_max_size`, PHP does not fail.** It hands the script an empty `$_POST` and
an empty `$_FILES`, with `Content-Length` still describing what was sent. The
handler reports "name is required", the user swears the field was filled in, and
the cause is an ini setting nobody has looked at.

The symptom is indistinguishable from an application bug, so the framework
detects the shape — a form body, a length over the ini limit, and no fields at
all — and says what really happened.

## What a request may contain

Input is **validated where it is used** (a `Schema`) and **escaped where it is
shown** (the template engine); nothing strips tags from it on the way in, because
what is dangerous depends on where a value ends up. Before a handler runs:

- text that is not valid UTF-8, or that contains a NUL byte, in the query, body
  or cookies is refused with 400;
- strings are normalised to NFC, so the same word typed on two keyboards is the
  same bytes;
- a JSON body nested deeper than 32 levels reads as malformed;
- a `Host` header that is not a host name, an IPv4 address or a bracketed IPv6
  address (optionally with a port) is refused with 400, whatever else is set:
  `evil.example/x`, a space or a quote is never what a browser sends;
- with `security.trusted_hosts` set (`['example.com', '*.example.com']`), any
  other `Host` header is refused with 400. It is empty by default, which
  `security:check` warns about.

Absolute links (password reset, email verification, OAuth callbacks) are built
from `APP_URL`, never from the `Host` header, so a forged `Host` cannot put the
attacker's domain into a link the application emails.

## Headers

These go on every response, error pages included — the ones most likely to be
reached by somebody probing, and the ones a per-route mechanism would miss:

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Cross-Origin-Opener-Policy: same-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

**An existing header is never overwritten.** A handler that set its own policy
has thought about it harder than a default can, and the asset server's
deliberately strict CSP would otherwise be loosened.

**Nothing names the framework.** There is no `X-Powered-By` and no `X-Engine`:
a header saying which framework answered tells a scanner which advisories to
try first. `http.headers => ['X-Engine' => 'LPHP']` sends one if you want it.

**HTML pages get a Content-Security-Policy when debug is off and none is
configured:**

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data:; object-src 'none'; base-uri 'self';
frame-ancestors 'self'; form-action 'self'
```

Scripts only from this origin, so an injected `<script>` or `onclick` does not
run; no plugins; nothing can frame the page or point its forms elsewhere.
Inline styles are allowed (the built-in error page has one, and a style cannot
run code). JSON, assets and downloads do not get it: nothing runs in them.

- An application that loads a script from a CDN, or writes inline script, sets
  its own in `security.headers.csp`, which replaces the default everywhere.
- `security.headers.csp => ''` sends none at all; `security:check` warns.
- With debug on it is not sent: the debug page is itself inline script.

**HSTS is off by default, and only ever sent over HTTPS.** It is the one header
here that cannot be taken back: a browser that has seen it refuses plain HTTP
for the whole `max-age`, so sending it from a development machine breaks every
other project on that hostname.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A form post is refused with 403 | No `_token` field, a blocked cookie, or a cross-site `Origin` | The reason is in the log; check the form and cookies |
| An API gets 403 on every POST | It authenticates by token but did not opt out | `meta(['csrf' => false])` on that group |
| 413 on an upload | Over `MAX_REQUEST_BYTES`, or over php.ini's `post_max_size` | The message says which; raise that one |
| "field is required" for a field you filled in | The upload exceeded `post_max_size` | Raise `post_max_size` and `upload_max_filesize` |
| 429 while developing | Rate-limit counters are files that outlive the process | Delete `system/Security` |
| A webhook is refused with 400 | The wrong secret, a body changed by a proxy, or a clock more than five minutes out | Check the secret and the clock |
| Every webhook comes back as a duplicate, or none ever does | Ids are remembered for `remember` seconds, in the cache or, with the `array` cache, in `system/Cache/webhooks/` | Check that directory is writable, and that the sender sends a fresh id per event |
| `security:check` exits 1 | Debug is on in production, or counters are in memory | It names the failure |
| An upload is refused with several messages | `check()` returns every problem at once | Fix them together |

## What was already true

Four concerns were structural before there was a security layer, and are now
enforced rather than merely intended:

| Concern | What makes it true |
|---|---|
| SQL injection | the grammar is the only thing that builds a statement |
| Path traversal | `AssetResolver` is the only thing that turns a path into a file |
| XSS | `Escaper` escapes by context; Twig autoescapes |
| Directory access | every server serves `public/` only, which holds the front controller and assets; a test and `security:check` keep it that way |

That last one used to be a list of folders and files to deny, kept in step
across `.htaccess`, the development router and nginx. It is now a document root
that contains nothing to deny, so there is no list to fall behind.

## Why there is no middleware

Security is attached by the framework at bootstrap, not by a module that could
forget — three listeners on hooks the kernel already fires:

| Hook | Does |
|---|---|
| `request.received` | the body size limit, before anything reads a body |
| `dispatch.before` | CSRF and the rate limit, with the route in hand |
| `response.instance` | the CSRF cookie and the security headers |

A middleware stack is a pipeline every request walks whether or not each layer
has anything to say, ordered by a list somebody maintains, and the usual failure
is a layer that silently stopped running because it was registered in the wrong
place.

Here the seams are named events, ordering is a priority number, and **a listener
refuses by throwing an `HttpException`** — which the kernel already turns into a
response, because that is how 404 and 405 work.

The cost, stated plainly: a listener cannot wrap the handler, so there is no "do
this after the response, in the same closure". The response filter covers the
other half, and nothing here has needed more.
