# Repositories and queries

A *repository* is the class your application asks for records. A *query* is how
it asks. This page covers writing a repository, the query methods, how much of a
row to turn into objects, and writing many rows at once.

To build your first one from nothing, follow
[Storing data](../guides/storing-data.md); this page explains the pieces.

## Write a repository

```php
final class CustomerRepository extends Repository
{
    protected function model(): string      { return Customer::class; }
    protected function collection(): string { return 'customers'; }

    public function findByEmail(string $email): ?Customer
    {
        return $this->query()->whereIs('email', $email)->first();
    }

    public function register(array $attributes): Customer
    {
        return $this->persist(new Customer(null, $attributes['name'], $attributes['email']));
    }
}
```

Two methods say what it is about: the model class, and the table (or collection)
name. Everything else is yours to name.

**There is no inherited `find()`, `findAll()`, `save()` or `delete()`.** The base
class publishes nothing but its constructor, and an architecture test keeps it
that way, so every public method on a repository is one somebody named after
something the application does.

A base class with all of those is a table gateway with a longer name: it says
nothing about the domain and grows a method per column.

The plumbing you build on is `final protected`:

| Method | Does |
|---|---|
| `query()` | a new query over this collection |
| `persist($model)` | insert or update, and return what is now stored |
| `remove($model)` | delete |
| `hydrate($row)` | turn a row into a model |

An update writes **only what changed** — that is what the model layer's change
tracking is for. `persist()` returns the stored model because a new one has no
identity until it is written, and reading the row back beats making every model
expose a writable identity for the engine's benefit.

## Build a query

```php
$query->whereIs('active', true)
      ->where('balance', Operator::Gt, 0)
      ->orderBy('name')
      ->limit(50);
```

Nothing is read until you call one of the methods in the next section. Each call
returns a **new** query, so a repository can hold a base query and hand out
narrowed copies without one caller changing what the next one sees.

| Method | Narrows to |
|---|---|
| `whereIs($field, $value)` · `whereNot(…)` | equal, not equal |
| `where($field, Operator::Gt, $value)` | any comparison in the `Operator` enum |
| `whereIn` · `whereNotIn` | a list |
| `whereNull` · `whereNotNull` | a missing or present value |
| `whereLike($field, $pattern)` | a text pattern |
| `select(…)` · `orderBy` · `orderByDesc` · `limit` · `offset` | shape of the result |

Criteria always combine with **AND**.

## Decide how much to build

This is the choice that most often turns a fast query into a slow page:

| Call | Gives you | Reads |
|---|---|---|
| `count()` · `exists()` | a number, a bool | no rows at all |
| `value($field)` · `column($field)` | one value, one list of values | that one field |
| `rows()` · `firstRow()` | plain arrays | every selected column, builds nothing |
| `into($readModel)` · `firstInto()` · `pageInto()` | read models | only the columns the read model declares |
| `get()` · `first()` · `stream()` | full domain models | everything, hydrated and identity-mapped |
| `page()` · `pageInto()` | a numbered page and a total | as above, plus a count |
| `cursor()` · `cursorInto()` | a page and a cursor to the next | as above, no count; fast at any depth |
| `chunk()` | every row, a batch at a time | as above, by key rather than by offset |

A list screen that builds ten thousand domain objects to show three columns is
the classic mistake, so the cheap options are first-class rather than an
optimisation to find later.

`pageInto()` works out its column list from the read model's own constructor, so
the columns selected cannot drift from the fields being built.

## Two ways to paginate

| | `page($n, $perPage)` | `cursor($perPage, $cursor)` |
|---|---|---|
| Gives | page *n* of *N*, and a total | this page, a next cursor and a previous one |
| Costs per page | reads past every earlier row, plus a `COUNT(*)` | reads only this page, no count |
| Page 5,000 | slow | as fast as page 1 |
| Rows added while paging | can shift a row onto two pages, or off every page | never |
| Use for | admin screens with page numbers, small or medium tables | APIs, feeds, exports, infinite scroll, large tables |

`page()` uses `OFFSET`, and a database answers `OFFSET 100000` by reading and
discarding 100,000 rows. `cursor()` remembers the last row it showed and asks
for the rows after it instead, which an index answers directly.

```php
// In a repository
public function feed(?string $cursor): CursorPage
{
    return $this->query()
        ->whereIs('spam', false)
        ->orderByDesc('created_at')
        ->cursorInto(MessageSummary::class, 20, $cursor);
}

// In a handler: ?cursor= is whatever the previous page handed out
try {
    $page = $messages->feed($request->query('cursor'));
} catch (DataException) {
    throw HttpException::badRequest('That cursor is not valid for this list.');
}

return ApiResponse::collection($page->items(), [
    'next' => $page->nextCursor(),          // null on the last page
    'previous' => $page->previousCursor(),  // null on the first
]);
```

- **The order is made total for you.** The query's `orderBy()` columns, then the
  key (`id`, or the repository's `key()`) in the same direction as the last
  column, so rows that tie on `created_at` still have one fixed order. With no
  order at all it is the key ascending.
- **Index the order columns**, in order: `(created_at, id)` for the example. One
  index serves both directions. Without it a cursor is correct but no faster.
  Measured on SQLite with 200,000 rows, a page 190,000 rows in took 7 ms with
  `OFFSET` and 0.06 ms with a cursor.
- **A cursor belongs to its listing.** It carries the order it was made for, and
  one made for a different order, or edited by hand, is refused with a
  `DataException` — turn that into a 400 as above.
- **Order by columns that are never null.** A cursor cannot continue from a row
  whose order column is null, and says so.
- **There is no total and no "jump to page 37".** That is the trade; use
  `page()` where you need them.

`chunk()` walks the same way, by key, so a job that deletes or updates the rows
it is given neither skips nor repeats any.

## Two things a query does not have

**No `OR`.** A general boolean tree is the point at which a query builder becomes
a query language, with its own precedence rules and its own bugs. A read that
genuinely needs one is a named repository method over SQL, which the database
layer runs directly — see [The query builder](database.md#the-query-builder).

**No join.** A join is a relational idea, and the five-method interface under all
this cannot honour it for every kind of storage. Data from two places is loaded
in two queries and linked explicitly, which the model layer already does — and
which cannot decay into N+1 the way a lazy association can.

## Bulk writes

A billing run that marks four thousand invoices overdue should be one statement,
not four thousand loads, change sets and single-row updates.

Row-at-a-time is right for domain work, because rules run per object. It is the
wrong tool for a set. So a repository has three more pieces of `final protected`
plumbing, which you again wrap in a method you name:

```php
public function import(array $rows): int
{
    return $this->insertMany($rows);                  // as few INSERTs as the driver allows
}

public function deactivateOwnedBy(int $ownerId): int
{
    return $this->updateWhere($this->query()->whereIs('ownerId', $ownerId), ['active' => false]);
}

public function purgeInactive(): int
{
    return $this->deleteWhere($this->query()->whereIs('active', false));
}
```

Each returns the number of rows affected.

What is refused, and why:

| Refused | Why |
|---|---|
| a query with an order, a limit, an offset or a column list | a bulk write touches every row the criteria match, and nothing else about the query applies. `UPDATE … LIMIT` means different things on different databases |
| a query with **no criteria at all** | "change every row" should be written on purpose — `whereNotNull('id')` — not arrived at by a filter somebody forgot |
| rows naming different columns | a multi-row `INSERT` has one column list, and filling a gap with `NULL` would override the column's default |

Three consequences worth knowing:

- **No identities come back** from `insertMany()`. Getting them portably means
  one statement per row again, which is the cost being avoided.
- **Loaded models are forgotten** after `updateWhere()` and `deleteWhere()`,
  because the database changed underneath objects the identity map would
  otherwise keep handing out.
- **No transaction is opened.** A large insert is split to stay under the
  driver's placeholder limit (999 on SQLite, 2000 on SQL Server, 65535 on MySQL
  and PostgreSQL), and whether a failure in the third statement should undo the
  first two is your decision:

```php
$connection->transaction(fn () => $customers->import($rows));
```

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Everything saved is gone next request | No database is configured, so the memory source is in use | Set `DB_DSN` |
| A bulk write is refused | The query has an order or limit, or no criteria at all | Remove the ordering, or add a criterion |
| A model's property is never filled | Column names must match the constructor's parameter names exactly | Rename the column, or the parameter |
| A list page is slow | It builds full models for a few columns | Use `into()` or `rows()` |
| `related()` throws | Related records are loaded on purpose, before use | See [Models](models.md) |

## Why it works this way

### One small interface under everything

`DataSource` is the whole seam between the data layer and storage — five
methods, with no connection, no statement and no dialect:

```php
fetch(Query): iterable   count(Query): int
insert(collection, key, row)   update(...)   delete(...)
```

`ArraySource` implements it in memory, and it is not a toy: a repository tested
against it runs the real repository, the real query and the real hydration at
full speed, with no database to install and nothing to clean up between tests.
`SqlSource` is the PDO-backed one — see
[Running the data layer on it](database.md#running-the-data-layer-on-it) — and
nothing above this interface changes between the two.

Bulk writes live on a second interface, `BulkWrites`, rather than as three more
methods on `DataSource`. Five methods is the point: it is a seam a non-relational
store can implement, and a store that cannot write a set is still a perfectly
good store. Ask one for a bulk write and the repository says so by name instead
of looping quietly. Both shipped sources implement it, and a conformance suite
runs every behaviour above against both.

### Why transactions are not here

Transactions, connections and nested-transaction strategy belong to the database
layer — `Connection::transaction()`, in [The database](database.md). A
`transaction()` on `DataSource` would make every source pretend to have one,
including the array in a test.
