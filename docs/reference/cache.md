# Cache

A *cache* stores the result of something expensive so the next request does not
have to work it out again. This page covers how to use one, which stores exist,
and how to make sure what you cached does not go stale.

## Cache a value

Ask for a `Cache` in your constructor, then use `remember()`:

```php
final class CustomerQuery
{
    public function __construct(private readonly Cache $cache) {}

    public function total(): int
    {
        return $this->cache->remember('customers.total', fn(): int => $this->query()->count(), 300);
    }
}
```

`remember()` returns the stored value if there is one. If not, it runs the
closure, stores what it returns for 300 seconds, and returns it.

**There is no `Cache::get()`, no `cache()` helper and no static method
anywhere.** A class that caches says so in its constructor, which means its test
can hand it a store that keeps nothing. An architecture test holds that line.

| Method | Does |
|---|---|
| `get($key, $default = null)` | the value, or the default |
| `has($key)` | true even for a stored `null` |
| `set($key, $value, ?int $ttl = null)` | `$ttl` is seconds; `null` uses the configured default |
| `delete($key)` · `clear()` | one key, or this whole namespace |
| `remember($key, fn() => …, ?int $ttl)` | compute once |
| `refresh($key, fn() => …)` | compute again and replace |
| `namespace($name)` | another `Cache` over the same store |

## The stores

Set which one with `CACHE_STORE`:

| Store | Keeps things | Use when |
|---|---|---|
| `array` | in memory, for this one request. **The default** | you have not thought about it yet |
| `file` | one file per entry under `system/Cache/data` | one machine serves the site |
| `database` | in a table every machine shares | more than one machine serves the site |
| `null` | nowhere; everything still works | you want caching out of the way |

**Why memory by default?** For the same reason the log has no writers by
default: a framework that starts writing files into a folder nobody asked about
fills up a disk on somebody else's machine. It is a real cache even so — a page
that resolves the same template from six partials pays for one search — and
`CACHE_STORE=file` is how you opt into keeping values between requests.

**With `APP_KEY` set, the file and database stores seal what they write.** A
value is stored with `serialize()`, so it can be an object, and reading it back
with `unserialize()` runs code from whatever wrote it. Each entry carries an HMAC
made with a key derived from `APP_KEY`, and one that does not match — written by
anybody else, or before the key changed — is a miss and is never unserialised.
Setting or rotating `APP_KEY` therefore empties the cache, which is all it costs.

### The database store

This is the shared cache that needs nothing installed beyond the database you
already have. Behind a load balancer, each machine with a `file` cache warms and
clears its own copy; a table is one copy.

It is **not a fast cache** — every hit is a query — so it pays where a value
costs much more than a query to work out.

```bash
php laika migrate     # with CACHE_STORE=database, creates the table
```

The table is `cache.table` (default `cache`) on `cache.connection` (the default
connection). The key is stored as a SHA-256 hash, so every database compares it
byte for byte, and clearing a namespace is one indexed `DELETE`. A database the
store cannot reach counts as a miss, not an error, as it does for any store.

### APCu, Redis and Memcached are not built

None of those three extensions was installed on the machine this was written on,
so every line would be unverified — and the one operation that genuinely differs
between backends is clearing a namespace without clearing somebody else's keys,
which is exactly the part that cannot be written blind.

What ships instead is the thing that makes adding one safe:
`tests/Unit/Cache/StoreConformanceTest` runs the same twenty-odd assertions
against every store, and an architecture test fails if a store exists that it
does not cover. A Redis store is five methods and one line in a data provider.

## A stored `null` is not a miss

A cache that answers `null` for both cannot hold a `null`, and the consequence
is not theoretical: `remember()` around a lookup that legitimately answers "no
such customer" would run the query on every single request, silently, while
appearing to work.

So:

- a miss is a missing entry, and a stored `null` is an entry whose value is
  `null`;
- `has($key)` and `get($key) !== null` are different questions;
- `remember()` caches a computed `null`.

Every store is held to that by the conformance test.

## Keys and namespaces

A key is up to 128 characters of letters, digits, dot, dash, colon and
underscore. Keys are **checked, not escaped**, because a key becomes a filename
in one store and part of a wire protocol in another; one set of rules both can
keep beats two escaping schemes that disagree.

A value that cannot leave this process — a closure, an open file — is refused by
`Cache` itself, so memory and files fail the same way.

Namespaces keep one part of the application's keys away from another:

```php
$billing = $cache->namespace('billing');   // keys become "billing:..."
$billing->clear();                         // and nothing outside billing goes
```

In the file store a namespace is a folder, so clearing one is removing the
folder rather than reading every entry to find out whose it is. Namespaces
compose, and `namespace()` returns a *new* `Cache` rather than changing the one
you called it on, so handing a module its own costs nothing.

## Keeping cached values true

A TTL is a backstop, not a plan. The thing that knows a cached count is wrong is
the event that says a customer was created:

```php
$module->onBoot(static function (CustomerQuery $customers, HookEngine $hooks): void {
    $hooks->add('customer.created', $customers->forgetTotal(...), 5, 'Example');
});
```

Hooks are already the framework's way of announcing things, so invalidation
needs no machinery of its own. Declare it in `onBoot`, where the dependency can
be injected.

A cache whose only invalidation is time is a cache that is usually wrong for a
while.

## Clearing

```bash
php laika cache:clear             # config, modules, templates and the application cache
php laika cache:clear --expired   # only entries whose TTL has passed; the rest stay warm
php laika cache:warm              # then rebuild the production boot path
```

`cache:clear` asks the store rather than deleting files, which is the version of
this that stays correct when the store is not files.

`--expired` belongs in a nightly schedule rather than in a deployment:
everything a deployment cares about has no TTL to have passed. The file and
database stores both sweep; a store whose backend expires entries itself says so
instead.

Reading an expired entry deletes it on the way past, so anything still being
asked for tidies up on its own.

## Locks

Only one process at a time — one worker generating a report, one request
rebuilding a cache entry:

```php
$lock = $cache->lock('report.' . $id, seconds: 120);

if ($lock->get()) {
    try {
        // ... the work ...
    } finally {
        $lock->release();
    }
}

// Or wait up to 5 seconds for it, run, and release whatever happens:
$cache->lock('report.' . $id, 120)->block(5, fn() => $this->build($id));
```

- **Every lock expires** after its seconds, so a process killed while holding
  one does not hold it for ever. Choose a little more than the longest run.
- **Only its holder releases it.** Each `Lock` carries a random owner token;
  `release()` removes the lock only while it still holds that token, so a
  process that overran and lost its lock cannot release the next holder's.
  Another process can release it with `$cache->lock($name, $seconds, $owner)`.
- **As wide as the store.** The **file** store locks across every process on one
  machine, the **database** store across machines, the **array** store within
  one process only. The **null** store refuses: a lock everyone gets is no lock.
- `block()` throws `CacheException` if the lock is not free in time.

A store of your own supports locks by implementing `AtomicStore`: `add()` (store
only if nothing unexpired is there, atomically) and `forgetIf()` (remove only if
it still holds the given value). The conformance suite checks both.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Cached values vanish between requests | `CACHE_STORE` is `array`, the default | Set `file`, or `database` for several machines |
| One machine serves stale values | Each machine has its own `file` cache | `CACHE_STORE=database` |
| `remember()` runs the closure every time | The value is `null`, and the store is fine — check the key is stable | Keys change if you build them from a timestamp |
| A key is refused | It is over 128 characters, or has a character outside the allowed set | Shorten it, or use a namespace |
| A value is refused | It is a closure or a resource, which cannot leave this process | Cache the data, not the object holding it |
| A config change does nothing | The configuration cache is a separate thing | `php laika cache:clear`, then `cache:warm` |

## Why it works this way

### What the framework caches, and how

Everything that caches is **handed a namespace at bootstrap**. Nothing reaches
for one itself.

- **`assets`** — version tokens. Content hashing reads the whole file, and a
  page referencing five assets hashes five files on every request. Cached, that
  is once per deployment. **Not in debug mode**: a hash that outlived the file
  it describes would mean editing a stylesheet and the browser keeping the old
  one, which is the exact failure content hashing exists to prevent.
- **`templates`** — resolved names. The search is folders times extensions of
  `is_file`, and a page with a layout and six partials does it seven times. A
  remembered resolution is checked against the filesystem before it is trusted,
  so a cache that outlived the layout degrades into a slow lookup rather than a
  missing template. Misses are never stored — a missing template is an
  exception, and caching one would turn "add the file" into "add the file and
  clear the cache".

### Two caches that do not use this at all

The module discovery list and the compiled configuration keep their own
`var_export` files, built by `cache:warm` (see
[The production boot path](../operations/deployment.md#the-production-boot-path)).

They hold plain data that changes only at deployment time, which is what
opcache is better at than anything here could be. And configuration is read
before a `Cache` can exist at all: a cache subsystem configured by the
configuration it caches is a bootstrap problem, not a design.
