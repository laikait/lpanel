# Authentication and authorization

Two different questions:

- **Authentication** — who is making this request? The answer is an `Identity`.
- **Authorization** — may they do this? The answer is yes or no.

This page covers both. To build a working login from nothing, follow
[Users and permissions](../guides/users-and-permissions.md) first; this page
explains the pieces it uses.

## Protect a route

```php
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])
       ->meta(['auth' => true, 'can' => 'invoice.void']);
```

- `'auth' => true` — somebody must be logged in.
- `'can' => 'invoice.void'` — and they must hold that capability.

`can` implies `auth`, so writing both is optional. Two commands show you the
result:

```bash
php laika auth:access           # every capability, role and guarded route
php laika auth:hash 'hunter2'   # a hash, for seeding the first account
```

## The framework does not know what a user is

There is no `User` model in `engine/`, no users table it expects, and no column
it names. Instead there is an interface with three methods:

```php
interface UserProvider
{
    public function describe(): string;      // a line for `php laika about`
    public function byId(string $id): ?Account;
    public function byLogin(string $login): ?Account;
}
```

Your module writes a class implementing it and binds it:

```php
$services->singleton(UserProvider::class, StaffProvider::class);
```

Everything that knows what a user *is* lives in that module. Point it at LDAP
and nothing in `engine/` changes.

An architecture test freezes that list of three methods, because a fourth lookup
is how an interface starts describing a database schema it does not own.

**A fresh installation binds no provider**: there are no demo accounts and no
login route. Until a module binds one, every request is a guest and every
protected route answers 401. That is the right behaviour for an application with
no user store, and `php laika security:check` says so rather than leaving you to
discover it.

## Capabilities and roles

| Word | Means |
|---|---|
| **Capability** | a name, such as `invoice.void`. A noun, not a question |
| **Permission** | a capability some module declared, with a sentence saying what it means |
| **Role** | a named set of capabilities, which may inherit other roles |
| **Subject** | whatever the decision is about — passed through, never interpreted |

Declare them in `module.php`:

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('invoice.void', 'Cancel an invoice that has been issued.');
    $access->role('accountant', ['invoice.*'], ['clerk']);
});
```

That role holds every `invoice.` capability, plus everything the `clerk` role
holds.

A check asks one question: do this identity's roles, flattened out, cover the
capability? **No closure runs.** `invoice.*` covers `invoice.void` and
deliberately does **not** cover `invoices.void` — matching a prefix without the
dot is how a new capability quietly falls inside somebody's old role.

An identity carries its roles, not its capabilities:

```php
$auth->check();                                   // is anybody logged in?
$auth->identity();                                // the Identity (guest if nobody)
$authorizer->allows($identity, 'invoice.void');   // true or false
$authorizer->authorize($identity, 'invoice.void'); // or throws
```

## Narrowing a decision for one record

A set of capabilities cannot answer "may this user edit *this* invoice", because
that is a fact about the data, not about the user. A module may narrow the
decision with a filter:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

**A filter may refuse. It may not grant.** If the set of capabilities already
said no, the filter is not even run. Anything other than an explicit `true` is
a refusal, so a listener that forgets its `return` fails closed.

## A missing capability stops the boot

```
Nothing declares the capability "invoice.viod", required by route POST /invoices/{id}/void.
```

Without this check the route would refuse **everybody**, including an
administrator holding every role, and would look like a routing fault or a
broken login. The one thing it would never say is that the capability does not
exist.

The check runs after every module has registered, because the module that
declares a capability may well load after the module that routes to it.

## 401 and 403 are different answers

**401 means "say who you are". 403 means "I know who you are, and the answer is
still no."**

Retrying a 401 with credentials may work; retrying a 403 with the same ones
never will. A client that cannot tell them apart retries for ever.

Every 401 carries `WWW-Authenticate: Bearer`, which RFC 9110 requires. Basic
authentication is deliberately not offered: it makes the browser show a login
box your application cannot style, cancel or explain.

## Logging in

```php
$identity = $auth->attempt($username, new Secret($password));

if ($identity === null) {
    // wrong credentials — say only that
}
```

**Every failure is the same failure.** No such account, wrong password,
suspended account — one answer, and your code must not explain which. "No
account with that email" tells a stranger which addresses are registered, and
the usual next step is to try that address on other sites. A missing account
still costs a password hash, because a wrong username answering in microseconds
and a wrong password answering in fifty milliseconds is the same disclosure by
another route.

**Guessing is throttled**, inside `attempt()` and `verify()`, so every login
form gets it without a `rate_limit` on its route. Only failures count, and a
guess past a limit is answered with **429** (with `Retry-After`) before the
password is looked at, so a right guess there says nothing either:

| Limit | Default | Stops |
|---|---|---|
| `auth.throttle.per_login_ip` | `5/15m` | one address guessing one account; the right password clears it |
| `auth.throttle.per_ip` | `30/15m` | one address trying a list of accounts |
| `auth.throttle.per_login` | `100/1h` | many addresses guessing one account |

The per-account limit is what an attacker could use to lock somebody else out,
so it is deliberately loose: it stops a botnet, not a user who mistyped. Counts
live in `security.counters`, like every other limit; `auth.throttle.enabled =>
false` turns this off, and `auth.throttled` fires when it refuses.

Three things happen on a successful `attempt()`:

1. **The session id is regenerated.** An attacker who planted a session id in
   the victim's browser before the login holds one that stopped meaning
   anything the moment it succeeded.
2. **The CSRF token rotates with it.**
3. **The session stores the user's id — never the `Identity` itself.**

`logout()` invalidates the whole session rather than forgetting one key: the id
changes and the data goes with it, so a basket, a half-finished form or the
previous user's filters do not survive for whoever sits down at that machine
next.

Storing only an id means roles are re-read on every request. Revoking a role
takes effect on the next click rather than the next login, and suspending an
account ends its session immediately. The cost is one `byId()` lookup per
authenticated request, which is the method worth making fast.

The session key holding that id is **reserved**: keys beginning with `_` cannot
be written through `Session::set()`. Otherwise any code that puts a user-supplied
key into the session — a `fill()` over request input is the obvious one — would
be a way to log in as anybody.

## API tokens

```
Authorization: Bearer <token>
```

Token authentication is registered only when your provider also implements
`TokenProvider`, which adds one method:

```php
public function byToken(string $token): ?Account;
```

An application without tokens therefore does not carry a listener that can never
succeed.

**A token beats a session when both are present.** A token was put on the
request deliberately; a cookie was attached by the browser on its own.

That difference is also why an API authenticated by tokens may opt out of CSRF
protection: another site cannot make a request that carries your token. An API
authenticated by a *session cookie* needs CSRF exactly as much as a form does.

**Store a fingerprint, not the token.** `TokenAuthenticator::fingerprint()` is
SHA-256, and tokens are looked up by that. A table of usable tokens is a
password table that skipped the last thirty years. A fast hash is right here and
a slow one would be wrong: 32 random bytes are not guessable offline, and bcrypt
on every API request would make a read endpoint slower than the query behind it.

### Apache eats the `Authorization` header

Apache receives `Authorization` and does not pass it to PHP. It is absent from
`$_SERVER` entirely unless `CGIPassAuth` is set or a rewrite copies it.

The failure is silent and specific to the environment: token authentication
passes every test, works under `php -S`, and answers 401 to everything once
deployed. The framework recovers the header from `getallheaders()` and from
`REDIRECT_HTTP_AUTHORIZATION`, and the shipped `.htaccess` sets the latter. Both,
because `getallheaders()` does not exist under FastCGI and the rewrite does not
exist on nginx.

## Passwords

Hashing uses `PASSWORD_DEFAULT`, never a named algorithm. Naming bcrypt pins the
application to whatever was current when somebody typed it.

A PHP hash records which algorithm made it, so old and new hashes coexist with
no migration. When `password_needs_rehash()` says a hash is out of date, the
`auth.rehash` hook fires — at the one moment the application holds the plaintext,
which is somebody logging in. Writing the new hash is your module's job, because
only your provider knows where hashes live.

The plaintext arrives as a `Secret`. Not so that it cannot be read, but so that
reading it says `reveal()` at the call site. An architecture test keeps
`Auth\Password` the only place in the project that calls `password_hash()`.

## Password reset and email verification

```php
public function __construct(
    private readonly PasswordReset $resets,
    private readonly EmailVerification $verification,
) {}

// POST /forgot-password
$this->resets->request($email, $request->ip());   // false only when rate limited
// …then the same page whatever the address: "If it has an account, a link is on its way."

// GET /account/reset?token=…  — show the form only while the link is good
$this->resets->check($token) ?? throw HttpException::notFound();

// POST /account/reset
$identity = $this->resets->reset($token, new Secret($password));   // null: not good any more

// after sign-up, and "send it again"
$this->verification->send($account);

// GET /account/verify?token=…
$identity = $this->verification->verify($token);   // null: invalid or expired
```

The framework ships no pages for these, as it ships no login page. It mails the
link, checks the token and changes the account; the routes and the forms are
yours. The link points at the route named in `auth.passwords.route`
(`password.reset`) or `auth.verification.route` (`email.verify`), with
`?token=…`.

**Your provider answers the questions only it can.** Implement
`PasswordResettable` (`emailFor()`, `resetPassword($account, $hash)`) and
`EmailVerifiable` (`emailFor()`, `isEmailVerified()`, `markEmailVerified()`)
on the class bound as `UserProvider`. Without them, each service refuses with an
error naming the interface.

**Links are built from `APP_URL`, never from the request.** The `Host` header
is whatever the client sent. A reset requested with `Host: evil.example` would
otherwise mail the victim a link to the attacker, token included. Without
`APP_URL` nothing is sent.

**Nobody learns which addresses are registered.** `request()` gives the same
answer for every address. It returns false only when a rate limit is hit: 3 a
quarter-hour per address, 10 per client IP, and addresses with no account count
too. The email is queued, so how long sending takes is not in the response
either, unless `QUEUE_STORE` is `sync`, which sends it inline.

**The tokens are stateless.** Each is signed with `APP_KEY` and expires (one
hour for a reset, a day for verification). Nothing is stored, so there is no
table to migrate or clean up. Each token is bound to what it changes:

- **A reset link works once.** It names a fingerprint of the account's current
  password hash. Setting the new password changes the hash, which ends that link
  and every other outstanding one, as any password change does.
- **A verification link verifies one address.** It names a fingerprint of the
  address it was sent to. Change the address and the link stops working.
  Verifying twice is harmless, so it may be clicked again.

`reset()` does not log the visitor in. Call `AuthManager::login()` if you want
it to. Hooks: `auth.password_reset_requested` (an account exists and was mailed),
`auth.password_reset` and `auth.email_verified`, each with the `Identity`.

## Two-factor login

```php
// POST /login, instead of AuthManager::attempt()
return match ($this->twoFactor->attempt($email, new Secret($password))) {
    TwoFactorResult::LoggedIn => redirect to the account,
    TwoFactorResult::ChallengeRequired => show the code form,
    TwoFactorResult::Failed => throw HttpException::unauthorized('Those details are not right.'),
};

// POST /login/code
$identity = $this->twoFactor->challenge($code);   // a code from the app, or a recovery code; null if wrong

// account settings: switching it on
$enrolment = $this->twoFactor->begin($account);    // ->qrSvg to show, ->secret to type in
$recoveryCodes = $this->twoFactor->confirm($account, $code);   // show these once
```

Codes come from an authenticator app (Google Authenticator, 1Password, Authy
and so on): TOTP, [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238), six
digits every thirty seconds. `Auth\Totp` is checked against the RFC's own test
vectors. A code from the step either side of now still counts, for a phone
whose clock is a little out.

- **A password alone logs nobody in** on an account with two-factor turned on.
  The account waits in the session for five minutes and five tries, and only a
  right code calls `AuthManager::login()`, which is when the session id changes.
  After five wrong codes the password has to be typed again.
- **And ten per account in fifteen minutes**, across every session, because
  typing the password again resets the session's five. Past that even a right
  code is refused until the window ends (`auth.two_factor_throttled` fires), and
  a right code clears the count. Rate-limit the code route by IP as well, as you
  do the login route.
- **A code works once.** The step it matched is stored, and that code or an
  older one is refused afterwards.
- **Turning it on takes two steps**, so a secret nobody scanned never locks
  anybody out. `begin()` makes the secret and its QR code (an SVG drawn by
  `Support\QrCode`, no library) and keeps them in the session. `confirm()` with
  a code from the app stores the secret.
- **Ten recovery codes** come back from `confirm()`, once, in plain text, for a
  lost phone. Only their hashes are stored, each works once, and
  `regenerateRecoveryCodes()` replaces the set.
- **Your provider stores it**, through `TwoFactorAccounts`. Keep the secret
  encrypted, for example as an `#[Encrypted]` model property, because whoever
  reads it can make codes.

`AuthManager::verify()` is `attempt()` without the login, for any other second
step. The authenticator app shows the name from `auth.two_factor.issuer`.

## Signing in with Google, Microsoft, Apple, GitHub or Facebook

```php
// config/auth.php
return [
    'social' => [
        'register' => false,   // true: a first sign-in creates an account
        'providers' => [
            'google'    => ['client_id' => Env::string('GOOGLE_ID'), 'client_secret' => Env::string('GOOGLE_SECRET')],
            'microsoft' => ['client_id' => …, 'client_secret' => …, 'tenant' => 'common'],
            'apple'     => ['client_id' => 'com.example.web', 'team_id' => …, 'key_id' => …, 'private_key' => Env::string('APPLE_KEY')],
            'github'    => ['client_id' => …, 'client_secret' => …],
            'facebook'  => ['client_id' => …, 'client_secret' => …],
            'staff'     => ['driver' => 'oidc', 'issuer' => 'https://sso.example.com/realms/staff', 'client_id' => …, 'client_secret' => …],
        ],
    ],
];
```

```html
<a href="/auth/google?return=/account">Sign in with Google</a>
```

The Shared module declares `GET /auth/{provider}` and the callback,
`GET|POST /auth/{provider}/callback` (named `social.callback`). Register that
callback with each provider as `APP_URL` + `/auth/<name>/callback`. A provider
that is not configured is a 404, so a fresh install answers nothing there.

**`oidc` is any OpenID Connect issuer**: Okta, Auth0, Keycloak, GitLab, your own.
`google`, `microsoft` and `apple` are the same driver with the issuer filled
in. Endpoints and keys come from the issuer's discovery document, cached for a
day; the keys for an hour.

**The flow is the authorization-code flow with PKCE, a `state` and an OpenID
`nonce`.** All three are made per sign-in and kept in a ten-minute cookie
encrypted with `APP_KEY`. A callback whose `state` is not this browser's is a
400, which stops an attacker signing a victim into the attacker's account. The
cookie is used rather than the session because Apple posts its callback from
its own site, and a session cookie set `SameSite=Lax` does not come with that
request. For the same reason the POST callback has no CSRF check: the `state` is
the check.

**The `id_token` is verified, not trusted**, with no library:

- its signature, against the issuer's published keys (RS256/384/512 or
  ES256/384/512; `none` and the HMAC algorithms are refused);
- then its claims: the issuer, this application as the audience, the expiry and
  the `nonce`.

A key that is not published yet is fetched again once, for a provider that has
just rotated keys. GitHub and Facebook are not OpenID providers: their answers
come straight from their APIs over TLS.

**Who the visitor is.** Your `UserProvider` implements `SocialAccounts`
(`findBySocial`, `findByEmail`, `linkSocial`, `createFromSocial`), and the
engine decides in this order:

1. the account already linked to that provider and id;
2. otherwise an account with the same email, linked from now on, **only when the
   provider says the email is verified**. Google and Apple do say so. Microsoft
   never does, and Facebook only when you set `'trust_email' => true`.
   Linking on an unproven address is how one person signs in as another;
3. otherwise a new account, when `register` is on. It has no password hash, so
   password login refuses it until one is set;
4. otherwise nobody: 403.

The `social.account` filter receives that answer (an `Account`, or `false`)
and the `SocialUser`, and has the last word: for example, a company domain only,
or always ask before linking.

**Two-factor still applies.** The provider is a first factor, the way a password
is: an account with two-factor turned on is left waiting for its code, exactly
as after `TwoFactor::attempt()`. The shipped callback redirects it to the route
named by `auth.two_factor.route` (`two_factor.challenge`), your code form, which
finishes with `TwoFactor::challenge()`; without that route the sign-in is
refused with 403. Otherwise turning two-factor on would protect nothing against
anybody who can sign in to the provider, or open an account there under the
same verified email.

**In your own handler**, the pieces are `SocialLogin::redirect()`,
`callback()` (returns a `SocialUser`), `attempt()` (a `TwoFactorResult`, as for
a password) or `login()` (an `Identity`, or null, including for an account that
needs its code), `account()` to decide yourself, `returnTo()` and
`forgetCookie()`. Anything else that signs somebody in without their password
goes through `TwoFactor::start($account)` for the same reason. A provider of your own implements
`Auth\Social\Provider` and is added with `$social->extend('name', …)`.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Every protected route answers 401 | No module binds a `UserProvider` | Bind one; see [Users and permissions](../guides/users-and-permissions.md) |
| The boot fails naming a capability | A route requires a capability nobody declared — usually a typo | Declare it with `$module->access()`, or fix the name |
| A user has the role but is still refused | An `authorization.decision` filter refused for that record | The filter is in the module that owns the data |
| Tokens work locally, 401 in production | Apache is dropping `Authorization` | Keep the shipped `.htaccess`, or set `CGIPassAuth On` |
| "An absolute link needs APP_URL" | Reset and verification links are never built from the `Host` header | Set `APP_URL` |
| "needs the UserProvider to implement PasswordResettable" | The provider cannot store a new hash | Implement the interface on your provider |
| 400 "This sign-in link is stale" | The flow cookie is missing: over ten minutes, another browser, or a cookie blocked | Start again; over HTTP in development the cookie is `Lax`, so Apple's POST will not carry it |
| 403 after a social sign-in | No account is linked and the email is not verified, or `register` is off | Link the account, or turn on `auth.social.register` |
| 500 "The id_token was refused" | Wrong `client_id`, a clock more than a minute out, or a misconfigured issuer | The message names the claim |
| `redirect_uri_mismatch` from the provider | The callback registered with the provider is not `APP_URL` + `/auth/<name>/callback` | Register exactly that |
| A reset link says it is not good | It was used, it is over an hour old, or the password changed since | Ask for another |
| Everyone is refused after adding a role | `invoice.*` does not cover `invoices.void` | Check the capability names with `php laika auth:access` |

## Why it works this way

### Why this is not a Gate

Laravel's Gate is a registry of closures keyed by ability strings: the closure
*is* the decision, it can say yes to anything, and finding out what the
application can express means reading every one of them. Policies are the same
thing, discovered by class-name convention. Both make "who may do what" a
question you can answer only by running the code.

Here, grants are data. `php laika auth:access` prints all of it, and an
architecture test fails if `Authorizer` ever gains a method that takes a
callback.

The filter above is the one contextual escape, and it is deliberately one-way.
Granting stays declarative and greppable; refusing stays contextual and lives in
the module that owns the data. A filter that could also grant would be a Gate
with extra steps.

### Why requiring a login is opt-in, when CSRF is on by default

The two defaults point in opposite directions, and the reasoning is not
inconsistent.

CSRF protection guards a route's side effects and costs a correct client
nothing, so having it on by default is free. Requiring a login by default would
make every page private, including the home page — so it would be switched off
wholesale on the first day, and a default that everybody disables protects
nothing while looking like it does.

What replaces it is visibility:

```
METHOD  PATH       NAME         MODULE         ACCESS      HANDLER
GET     /staff     staff.index  Staff  staff.list  ...
POST    /login     auth.login   Staff  public      ...
```

`php laika route:list` has an ACCESS column, `auth:access` lists which routes
check what, and **`security:check` warns about routes that change something and
require nobody**. Some of those are meant to be open — a login form has to be —
and the point is that you can see them.
