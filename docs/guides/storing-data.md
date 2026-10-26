# Storing data

This guide shows how to keep your application's data in a database:

1. connect a database,
2. create tables,
3. write a **model** (a class for one record) and a **repository** (the class
   that reads and saves them),
4. query, page through lists, and load related records,
5. change many rows at once, and use transactions,
6. write SQL yourself when you need to.

The examples build a `Contact` module that stores messages sent through a contact
form. Do [Getting started](../getting-started.md) first; it builds a smaller
version of the same thing.

## The pieces, and why there are several

| Piece | What it is | Reference |
|---|---|---|
| **Model** | A class for one record, such as a message, with the rules that keep it valid. | [Models](../reference/models.md) |
| **Repository** | The class that reads and saves models. Its methods are named after what your application does. | [Repositories and queries](../reference/data.md) |
| **Schema** | The shape of data that comes in or goes out, such as a form or a JSON body. | [Schemas](../reference/schemas.md) |
| **Connection** | Your database, for SQL written by you. | [The database](../reference/database.md) |

Most code only touches models and repositories. The connection is for reports
and anything a repository cannot express.

## Connect a database

Until you configure one, repositories store everything in memory, and it is gone
after each request. That is useful in tests, but nothing is kept.

For one database, set three environment variables. On your own machine, put them
in the `.env` file in the project's root folder:

```bash
DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=erp;charset=utf8mb4"
DB_USERNAME=erp
DB_PASSWORD=secret
```

`DB_DSN` says which database and where, in PDO's format. Other examples:

| Database | `DB_DSN` |
|---|---|
| MySQL or MariaDB | `mysql:host=127.0.0.1;port=3306;dbname=erp;charset=utf8mb4` |
| PostgreSQL | `pgsql:host=127.0.0.1;port=5432;dbname=erp` |
| SQLite | `sqlite:/absolute/path/app.sqlite` (always an absolute path) |
| SQL Server | `sqlsrv:Server=127.0.0.1,1433;Database=erp` |

For more than one database, or to write `host`, `port` and `database` as separate
settings, create `config/database.php` instead. See
[Configuring a connection](../reference/database.md#configuring-a-connection).

Your repositories do not change when you switch from memory to a database. The
choice is made in one place, `modules/Shared/module.php`.

## Create the tables

Each module creates its own tables with **migrations**: files in the module's
`Database/Migrations/` folder that describe a table with methods, not SQL. The
framework writes the right SQL for MySQL, PostgreSQL, SQLite or SQL Server, so
one file works on all four.

Create `modules/Contact/Database/Migrations/2026_09_19_120000_create_messages.php`:

```php
<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('messages', static function (Table $table): void {
            $table->id();
            $table->string('email', 190)->index();
            $table->text('body');
            $table->boolean('spam')->default(false);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('messages');
    }
};
```

This creates a `messages` table with:

- `id`: a number the database assigns to each row;
- `email`: text up to 190 characters, with an **index** so searching by email is
  fast;
- `body`: text of any length;
- `spam`: yes or no, `false` unless set.

`up()` makes the change; `down()` undoes it.

Run it:

```bash
php laika migrate --pretend   # show the SQL for this database, and run nothing
php laika migrate             # run every migration that has not run yet
php laika migrate:status      # list migrations, and whether each has run
php laika migrate:rollback    # undo the last run
```

Rules to know:

- **The file name sets the order.** It is `YYYY_MM_DD_HHMMSS_what_it_does.php`,
  in lower case, and you write it by hand; there is no generator. A wrong name
  stops the run before anything happens.
- **A migration runs once.** The framework records what ran in a `migrations`
  table. To change a table later, write a **new** migration that calls
  `$tables->alter(...)`. Never edit one that has already run.
- **Modules run in dependency order.** If your table points at another module's
  table (a foreign key), your module must `requires()` that module, so its tables
  exist first.
- **Column names must equal the model's constructor parameter names**, exactly.
  A parameter `$createdAt` needs a column `createdAt`.

### Starting data: seeders

Rows a module needs from the start go in a **seeder**, a file in the module's
`Database/Seeders/` folder:

```php
return new class implements Seeder {
    public function run(Connection $db): void
    {
        if (!$db->table('messages')->where('email', 'welcome@example.test')->exists()) {
            $db->table('messages')->insert(['email' => 'welcome@example.test', 'body' => 'Hello.']);
        }
    }
};
```

`php laika db:seed` runs every seeder, **every time**. That is why this one checks
the row is not there before inserting it.

Every column type, and the SQL each database gets, is in
[Migrations and seeders](../reference/database.md#migrations-and-seeders). If you
keep sessions, the cache, the queue or the log in the database, `migrate` creates
their tables too.

## A model

A **model** is one record as an object. `Message`:

```php
final class Message extends Model
{
    public function __construct(
        private ?int $id,
        private string $email,
        private string $body,
        private bool $spam = false,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSpam(): bool
    {
        return $this->spam;
    }

    public function markAsSpam(): void
    {
        $this->spam = true;
    }
}
```

- **Rows become models through the constructor.** Each column goes to the
  parameter with the same name. This is also where a model refuses bad data, by
  throwing an exception.
- **There are no setters and no `save()`.** You change a model with methods that
  mean something, like `markAsSpam()`. The model remembers what changed, so saving
  it later updates only those columns.
- **`identity()`** is the record's id. It is `null` until the record is saved.
- **Keep a model in the module that owns it.** Put one in `modules/Shared/` only
  when several modules need it.

## A repository

A **repository** reads and saves one kind of model:

```php
final class MessageRepository extends Repository
{
    protected function model(): string
    {
        return Message::class;
    }

    protected function collection(): string
    {
        return 'messages';
    }

    /** @param array{email: string, body: string} $attributes already checked against the schema */
    public function receive(array $attributes): Message
    {
        $stored = $this->persist(new Message(null, $attributes['email'], $attributes['body']));

        return $stored instanceof Message ? $stored : throw new \LogicException('Not a Message.');
    }
}
```

- `model()` says which class a row becomes; `collection()` is the table.
- `receive()` saves a new message. `persist()` returns the saved model, now with
  its id.

Register it in your `module.php`, inside `services()`:

```php
$services->singleton(MessageRepository::class);
```

Then ask for `MessageRepository` in the constructor of any class that needs it.

**A repository has no ready-made `find()`, `all()` or `save()`.** The base class
gives you tools, all `protected`: `query()`, `persist()`, `remove()`, `hydrate()`
and the bulk operations below. You write the public methods, and name them after
what your application does: `receive()`, `inbox()`, `purgeSpam()`. Reading the
list of methods then tells you what the application does with messages.

`persist()` inserts a new model, or saves the changed columns of one that already
exists.

## Query

Inside a repository, `$this->query()` starts a query. Each method adds a
condition, and nothing runs until the last call:

```php
$this->query()
    ->whereIs('spam', false)
    ->where('id', Operator::Gt, $after)
    ->whereIn('email', $addresses)
    ->orderByDesc('id')
    ->limit(50)
    ->get();                  // ModelCollection of Message
```

This reads: messages that are not spam, with an id greater than `$after`, from
one of these addresses, newest first, at most 50.

The last call decides what you get back:

| To get | Call |
|---|---|
| models | `get()`, `first()`, `stream()` |
| plain arrays | `rows()`, `firstRow()` |
| one column, or one value | `column('email')`, `value('email')` |
| a number, or yes/no | `count()`, `exists()` |
| read models (see next section) | `into()`, `firstInto()`, `pageInto()` |
| results in batches | `page()`, `chunk($size, $callback)` |

Conditions are always combined with AND. **There is no OR and no join** here,
because this query must work the same way on every kind of storage, memory
included. For OR or a join, write a repository method with SQL; see
[SQL directly](#sql-directly).

## Read only what a screen needs

A list page rarely needs full models. A **read model** is a small class with just
the fields a screen shows:

```php
final class MessageSummary extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
    ) {}
}
```

`pageInto()` reads only those columns, and one page of rows:

```php
/** One screen of the inbox: two columns, no domain objects built. */
public function inbox(int $page, int $perPage = 20): Page
{
    return $this->query()
        ->whereIs('spam', false)
        ->orderByDesc('id')
        ->pageInto(MessageSummary::class, $page, $perPage);
}
```

The `Page` it returns has `items()` (the rows), `total` (how many there are in
all), `pages()`, `hasMore()`, and `meta()` for a JSON response. A read model turns
into JSON as exactly its fields.

## Load related records

Say every customer has an owner, who is a user. You **declare** that relation
once, in your module's `onBoot()`, where `RelationManager` is passed in:

```php
// in onBoot, where RelationManager is injected
$relations->declare(Customer::class,
    Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'));
```

Then load the related records **in one extra query**, not one per customer:

```php
$customers = $this->customers->all();                                   // one query
$owners = $this->users->findAll($relations->keysFor($customers, 'owner')); // one more
$relations->link($customers, 'owner', $owners);                         // none

$customer->related('owner');   // the User, or null if it has none
```

`all()` and `findAll()` are methods you write on your own repositories.

Calling `related()` before `link()` **throws an exception**. So a loop can never
quietly run one query per row, the common mistake known as "N+1 queries".

## Change many rows at once

```php
/** Every spam message, in one statement. */
public function purgeSpam(): int
{
    return $this->deleteWhere($this->query()->whereIs('spam', true));
}
```

| To | Call |
|---|---|
| insert many rows | `insertMany($rows)` |
| update every row a query matches | `updateWhere($query, $values)` |
| delete every row a query matches | `deleteWhere($query)` |

Each returns the number of rows changed, and uses as few SQL statements as it
can. A query with **no condition is refused**, so a slip cannot empty a table.
To really mean "every row", write `whereNotNull('id')`. See
[Bulk writes](../reference/data.md#bulk-writes).

## Transactions

A **transaction** makes several changes happen together, or not at all. Open it
in the code that knows which changes belong together, not inside each repository
method:

```php
$connection->transaction(function (Connection $db) use ($invoice): void {
    $this->invoices->store($invoice);
    $this->entries->post($invoice);
    $this->payments->record($invoice);
});
```

- If the function throws, everything in it is undone (**rolled back**), and the
  exception continues up to your code.
- A transaction inside another becomes a savepoint, so the inner one can fail on
  its own.
- Get `$connection` from an injected `ConnectionManager`:
  `$connections->connection()`, or `connection('reports')` for a named database.

When two requests may change the same rows at the same moment, ask for a stricter
**isolation level**, and let the framework try again if the database gives up on
one of them:

```php
$connection->transaction($callback, isolation: IsolationLevel::Serializable, retries: 3);
```

> **The function may then run more than once.** Only a deadlock or a
> serialization failure is retried, and each try starts clean. So send the email
> or charge the card **after** `transaction()` returns, never inside it.

A level the database cannot provide is refused, not quietly weakened. See
[Isolation levels and retrying](../reference/database.md#isolation-levels-and-retrying).
To run code after a transaction has been saved, listen to
`database.transaction.committed`; see
[Watching statements and transactions](../reference/database.md#watching-statements-and-transactions).

## SQL directly

Reports, imports and anything a repository query cannot express go straight to
the connection. Its **query builder** has OR, joins, grouping and totals. Values
are always sent separately from the SQL, and names are checked:

```php
final class RevenueReport
{
    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return list<array<string, mixed>> revenue per region since a date */
    public function byRegion(\DateTimeImmutable $since): array
    {
        return $this->connections->connection()->table('customers AS c')
            ->select('c.region', Aggregate::sum('o.total', as: 'revenue'))
            ->join('orders AS o', 'o.customer_id', '=', 'c.id')
            ->where('o.issued_at', '>=', $since)
            ->where(fn (QueryBuilder $q) => $q->where('o.status', 'paid')->orWhere('o.status', 'settled'))
            ->groupBy('c.region')
            ->orderByDesc('revenue')
            ->get();
    }
}
```

This reads: for each region, the sum of paid or settled order totals since
`$since`, biggest first. The function inside `where()` makes the two status
conditions one group, like brackets in SQL.

The builder writes too: `insert()`, `update()`, `delete()`, and `upsert()` where
the database has one. It refuses an `update()` or `delete()` without a `where()`.
See [The query builder](../reference/database.md#the-query-builder).

For anything the builder cannot say, write SQL yourself. Put every value in the
array, never in the SQL string:

```php
$rows = $connections->connection('reports')->select(
    'SELECT customer_id, SUM(total) AS total FROM invoices WHERE issued_at >= ? GROUP BY customer_id',
    [$since],
);

$row = $connection->selectOne('SELECT * FROM staff WHERE email = ?', [$email]);   // ?array
$count = $connection->scalar('SELECT COUNT(*) FROM staff');
$connection->execute('UPDATE staff SET active = 0 WHERE id = ?', [$id]);
```

Each `?` is filled from the array, safely. **Never build a table or column name
from user input**: values can be sent separately, names cannot.

## Long-running processes

Within one process, loading the same row twice gives you the same object. A queue
worker runs many jobs in one process, so a job may see data another job already
loaded. When a job reads data that others may have changed, inject `ModelManager`
and call `flush()` at the start of its `handle()`.

## Testing data code

- **Without a database:** repositories run the same queries against memory, with
  nothing to install. Start the application with no database configured, and
  create test data through your own repository methods.
- **With SQL:** use an in-memory SQLite database, which starts empty for each
  test, then create the tables:

  ```php
  $app = $this->shippedApplication(['database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]]])->boot();
  $this->migrate($app);
  ```

See [Testing](testing.md).

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Data is gone after each request | No database is configured, so memory is used. | Set `DB_DSN` in `.env`. |
| `could not find driver` | PHP lacks the PDO driver for that database. | Enable it in `php.ini` (`pdo_mysql`, `pdo_pgsql`, ...). Check with `php -m`. |
| `no such table` / `Table ... doesn't exist` | The migration has not run on this database. | `php laika migrate`. |
| A model cannot be built from a row, or a property keeps its default | A column name does not match the constructor parameter's name. | Rename the column in a new migration, or the parameter. |
| "refused" on `update()` or `delete()` | The query has no condition. | Add a `where()`; for every row, `whereNotNull('id')`. |
| `related()` throws | The relation was never `link()`ed for these models. | Load the related records and call `link()` first. |

More in [Troubleshooting](../troubleshooting.md).
