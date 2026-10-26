# Users and permissions

This guide shows how to:

1. connect the framework to **your own** user accounts,
2. hash passwords,
3. let people log in and out,
4. protect routes so only logged-in people, or only some of them, can use them,
5. check permissions in code, including rules about one particular record,
6. let API clients log in with tokens.

Two words used throughout:

- **Authentication** answers *who is this?*: logging in.
- **Authorization** answers *may they do this?*: permissions.

The full rules are in [Authentication and authorization](../reference/auth.md).

## Start: a fresh install has no accounts

The framework has no users table and no `User` class of its own. It does not
know what a user is in your application. Until you connect your accounts:

- nobody can log in,
- no API token is accepted,
- every route that needs a logged-in person refuses everybody.

So there is no demo account to delete before going live.
`php laika security:check` shows which accounts source is in use.

## Step 1: connect your accounts

You tell the framework how to find an account by writing a **provider**: a class
that implements `UserProvider`, or `TokenProvider` if you also want API tokens.

This example keeps accounts in a `staff` table. First the table, as a migration:

```php
// modules/Shared/Database/Migrations/2026_09_19_120000_create_staff.php
return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('staff', static function (Table $table): void {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('password_hash');
            $table->string('roles')->default('');
            $table->boolean('active')->default(true);
            $table->string('token_fingerprint', 64)->nullable()->unique();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('staff');
    }
};
```

| Column | Holds |
|---|---|
| `email` | what people log in with; `unique()` allows each address once |
| `password_hash` | the hashed password, never the password itself |
| `roles` | role names separated by commas, such as `support,member` |
| `active` | `false` blocks the account from logging in |
| `token_fingerprint` | for API tokens, see the last section |

Then the provider:

```php
final class StaffProvider implements TokenProvider
{
    public function __construct(private readonly ConnectionManager $connections) {}

    public function describe(): string
    {
        return 'staff accounts, from the staff table';
    }

    public function byId(string $id): ?Account
    {
        return $this->account('id', $id);
    }

    public function byLogin(string $login): ?Account
    {
        return $this->account('email', $login);
    }

    public function byToken(string $token): ?Account
    {
        return $this->account('token_fingerprint', TokenAuthenticator::fingerprint($token));
    }

    /** The one row whose $column holds $value, as an Account. */
    private function account(string $column, string $value): ?Account
    {
        $row = $this->connections->connection()->table('staff')
            ->select('id', 'email', 'password_hash', 'roles', 'active')
            ->where($column, $value)
            ->first();

        if ($row === null) {
            return null;
        }

        return new Account(
            new Identity(
                (string) $row['id'],
                (string) $row['email'],
                \array_values(\array_filter(\explode(',', (string) $row['roles']))),
            ),
            (string) $row['password_hash'],
            (bool) $row['active'],
        );
    }
}
```

The framework calls these methods; you never call them yourself:

| Method | Called when |
|---|---|
| `byLogin()` | someone logs in with an email |
| `byId()` | **every** request from a logged-in person, to load them again |
| `byToken()` | an API request sends a token |

Each returns an `Account`, or `null` when there is no such account. An
`Account` holds:

- an **`Identity`**: who the person is for this request. Its id, a display name,
  and a list of role names.
- the password hash, and whether the account is active. An inactive account
  behaves exactly like a wrong password.

Because `byId()` runs on every request, removing someone's role or deactivating
their account works from their very next click. Keep `byId()` fast.

Finally, register the provider in your module's `module.php`:

```php
$module->services(static function (ServiceRegistrar $services): void {
    $services->singleton(UserProvider::class, StaffProvider::class);
});
```

This says: whenever the framework needs a `UserProvider`, use `StaffProvider`.
Register it in **one** module only, the one that owns your accounts; that is
`modules/Shared` if several modules need them. `php laika security:check` then
shows your provider.

## Step 2: passwords

Never store a password, only its **hash**, a one-way scrambled version. To make
the hash for a first account:

```bash
php laika auth:hash 'correct horse battery staple'
```

Put the output in the `password_hash` column, by hand or from a
[seeder](../reference/database.md#migrations-and-seeders).

In code, ask for `Password` in a constructor and call
`$password->hash(new Secret($plain))`. You never choose the hashing algorithm:
each hash records which one made it, so old and new hashes both keep working.

When PHP's recommended algorithm changes, the framework makes a new hash at the
person's next successful login and fires the hook `auth.rehash` with it. Store
it:

```php
$module->hook('auth.rehash', [StaffPasswords::class, 'store']);   // (Identity $identity, string $hash)
```

`StaffPasswords::store()` is your own static method that writes the new hash to
the `staff` table.

## Step 3: log in and out

The framework ships no login page, because the right one depends on your
application: a JSON endpoint, an HTML form, a second factor. You write it, and
call `AuthManager::attempt()`:

```php
final class SessionEndpoints
{
    public function __construct(private readonly AuthManager $auth) {}

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (!\is_string($email) || !\is_string($password)) {
            throw HttpException::badRequest('Send an email and a password.');
        }

        // Wrapped at once: from here the plaintext cannot reach a log or a response.
        $identity = $this->auth->attempt($email, new Secret($password));

        if ($identity === null) {
            // Say only that the details are wrong. Never which half.
            throw HttpException::unauthorized('Those details are not right.');
        }

        return new JsonResponse(['id' => $identity->id, 'name' => $identity->name]);
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return new JsonResponse(['authenticated' => false]);
    }
}
```

The routes:

```php
$routes->post('/login', [SessionEndpoints::class, 'login'])->meta(['rate_limit' => '5/1m']);
$routes->post('/logout', [SessionEndpoints::class, 'logout'])->meta(['auth' => true]);
```

What to know:

- **`new Secret($password)`** wraps the password straight away, so it can never
  end up in a log or an error message by accident.
- **`attempt()`** returns the `Identity` when the email and password are right,
  or `null`. On success it also gives the visitor a new session id, which stops
  an attack called *session fixation*.
- **Never say which part was wrong.** "Unknown email" tells an attacker which
  emails exist. `attempt()` even takes the same time either way, for the same
  reason.
- **Rate-limit the login route**, as above (5 tries a minute per IP address).
  Without a limit, anyone can guess passwords as fast as your server answers.
- **The login form needs its CSRF token** like every other form: send `_token`
  or the `X-CSRF-TOKEN` header. See [Pages and forms](pages-and-forms.md#a-form).
  Do not switch CSRF off for login: a forged login is exactly what the protection
  is for.

## Step 4: protect routes

Add `meta` to a route:

```php
$routes->get('/me/settings', MySettings::class)->meta(['auth' => true]);
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])->meta(['can' => 'invoice.void']);

$routes->group('/admin', static function (RouteCollector $routes): void {
    // ...
}, meta: ['can' => 'admin.access']);
```

| `meta` | Means | Refused with |
|---|---|---|
| `'auth' => true` | someone must be logged in | `401` for a guest |
| `'can' => 'invoice.void'` | they must have that **capability** (this includes being logged in) | `401` for a guest, `403` for someone without it |

Putting `meta` on a `group` applies it to every route inside.

**Routes are public unless you say otherwise.** `php laika route:list` shows each
route's access in its ACCESS column, and `php laika security:check` warns about
routes that change data but require nobody.

A handler gets the logged-in person by asking for `Identity`:

```php
public function __invoke(Identity $identity): array
{
    return ['hello' => $identity->name];
}
```

## Step 5: capabilities and roles

- A **capability** is permission to do one thing, named like `invoice.void`.
  Routes and code always check capabilities, never role names.
- A **role** is a named group of capabilities, such as `support`. People have
  roles; roles grant capabilities.

The module that checks a capability also declares it, in `module.php`:

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('message.read', 'Read what visitors sent through the contact form.');
    $access->role('support', ['message.*'], ['member']);
});
```

- `capability()` declares `message.read`, with a description.
- `role('support', ['message.*'], ['member'])` creates the role `support`, which
  grants every capability starting with `message.` and also everything the
  `member` role has. `message.*` matches `message.read`, but not
  `messages.read`.

If a route asks for a capability **nobody declared**, the application refuses to
start, and names the route. A typo therefore cannot lock everybody out without
anyone noticing.

To see everything:

```bash
php laika auth:access    # every capability, every role, which routes check what
```

## Step 6: check permissions in code

Ask for `Authorizer` in a constructor:

```php
public function __construct(private readonly Authorizer $authorizer) {}

$this->authorizer->allows($identity, 'invoice.void', $invoice);     // bool
$this->authorizer->authorize($identity, 'invoice.void', $invoice);  // or 401 / 403
```

- `allows()` answers `true` or `false`.
- `authorize()` does nothing when allowed, and stops the request with `401` or
  `403` when not.

## Rules about one particular record

"May this person edit **this** invoice?" depends on the invoice, for example on
who owns it. Add such a rule with the filter `authorization.decision`:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

This says: if the record is an invoice that someone else owns, refuse; otherwise
keep the decision as it was.

- **A filter can only take permission away, never give it.** Anything except
  `true` counts as a refusal, so a function that forgets to return anything
  refuses, which is the safe side.
- The filter sees the record only when your code passes it as the third argument
  of `allows()` or `authorize()`. A route's `can` check has no record to pass.

## API clients: tokens

An API client, such as a mobile app or another server, logs in by sending a
**token** with every request:

```
Authorization: Bearer <token>
```

With a `TokenProvider` registered, the framework passes that token to your
`byToken()`.

- **Store only the token's fingerprint**, `TokenAuthenticator::fingerprint($token)`,
  never the token itself, the same way you store a hash instead of a password.
- **Make tokens long and random:** `bin2hex(random_bytes(32))`. Show a token to
  its owner once, when you create it.

Another website cannot make a browser send a token, so an API used **only** with
tokens may switch CSRF off:

```php
$routes->group('/api/v1', static function (RouteCollector $routes): void {
    // ...
}, name: 'api.v1.', meta: ['api' => true, 'csrf' => false]);
```

If the API also accepts the login cookie, keep CSRF on.

**On Apache**, check that the `Authorization` header reaches PHP: Apache drops it
unless told otherwise. The `.htaccess` that ships passes it on; a virtual host
you configure yourself must too.

## Test it

A single test can do all of this: create the `staff` table in an in-memory
database, log in through your `/login` route, then open a protected route with
the session and with a token. See [Testing](testing.md#forms-cookies-and-csrf).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Every login fails, even with the right password | No provider is registered, or it is registered in a module that is not loaded. | `php laika security:check` shows the provider in use. |
| Login is right, but the next request is logged out | Sessions are not kept between requests. | See [Sessions](../reference/sessions.md); on several servers, use `SESSION_STORE=database`. |
| `403` on the login form | The CSRF token was not sent. | Add the `_token` field. |
| The application will not start: a capability is not declared | A route's `can` names a capability no module declared. | Declare it with `$access->capability(...)`, or fix the typo. |
| API requests with a token get `401` behind Apache | Apache dropped the `Authorization` header. | Pass it on in your virtual host, as the shipped `.htaccess` does. |

More in [Troubleshooting](../troubleshooting.md).
