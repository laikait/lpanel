# The database

This page is about talking to a SQL database directly: connecting, running
statements, transactions, the query builder, creating tables, and migrations.

The layer above this one — repositories, models and queries — is in
[Repositories and queries](data.md), and
[Storing data](../guides/storing-data.md) walks through building one from
nothing. **Nothing on this page needs a model.** A `Connection` is for a report,
a migration or a one-off script as much as for a repository, and an architecture
test keeps it that way.

## Configuring a connection

One connection needs no file at all, which is what a container image wants:

| Variable | Is |
|---|---|
| `DB_DSN` | the PDO DSN, e.g. `mysql:host=localhost;dbname=erp;charset=utf8mb4` |
| `DB_USERNAME`, `DB_PASSWORD` | credentials, if the driver needs them |
| `DB_CONNECTION` | what to call it, and which one is the default (default: `default`) |

More than one connection, or anything with options, goes in
`config/database.php` — where the environment is still reachable, because a
config file may call `Env` itself:

```php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'dsn' => Env::string('DB_DSN', 'mysql:host=db;dbname=erp'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
        'reports' => ['dsn' => 'pgsql:host=replica;dbname=warehouse', 'username' => 'reader'],
        'ledger' => [
            'driver' => 'mysql',
            'host' => 'ledger-db',
            'port' => 3306,
            'database' => 'ledger',
            'charset' => 'utf8mb4',
            'username' => 'ledger',
            'password' => Env::string('LEDGER_PASSWORD'),
        ],
    ],
];
```

A connection gives either a `dsn` or its **parts** — `driver`, `host`, `port`,
`database`, `charset` — which are assembled for `mysql`, `pgsql`, `sqlite`
(where `database` is the file, or `:memory:`) and `sqlsrv`. `charset` is written
for MySQL only; PostgreSQL takes the database's encoding, and SQL Server's is a
PDO attribute. A `dsn` wins when both are given, and any other driver needs one.

A part containing `;`, or a host containing `,`, is refused rather than escaped:
no driver documents an escape, and either would start a DSN part the
configuration never set.

**With nothing configured the application boots and opens nothing**, and the
shared module's `DataSource` factory falls back to the in-memory source.

### Several databases

Heavy applications rarely have one database — a reporting replica, a legacy
system being migrated from, a separate ledger — so each is a name:

```php
$connections->connection('reports')->select('SELECT ...', [$id]);
$connections->connection();                        // the default one
```

**Connecting is lazy.** Building a `Connection` opens nothing; the PDO handle is
created on first use. A request that never reads the database never opens a
socket, which is what makes it reasonable to declare every database the
application *might* touch.

## Running a statement

```php
$connection->select('SELECT * FROM invoices WHERE customer_id = ?', [$id]);
$connection->selectOne(...);  $connection->scalar(...);  $connection->cursor(...);
$connection->execute(...);    $connection->insert(...);
```

| Method | Returns |
|---|---|
| `select` | every row, as arrays |
| `selectOne` | the first row, or null |
| `scalar` | the first column of the first row |
| `cursor` | a generator, one row at a time |
| `execute` | the number of rows affected |
| `insert` | the generated key, where the database gives one |

**Every method takes the SQL and a separate list of values.** A value never
becomes part of the statement text. Prepared statements are real rather than
emulated (`ATTR_EMULATE_PREPARES => false`), which matters twice: emulation
interpolates values into the statement, and it hands every column back as a
string.

A failing statement reports the SQL and the **number** of bound values, never
the values themselves. A bound value is the one thing in a query likely to be a
password.

### How PHP values are bound

| PHP value | Bound as |
|---|---|
| `int`, `bool`, `null`, `string` | their own PDO types |
| `float` | the shortest decimal that reads back as the same float — PDO alone would write fourteen digits and store `0.1 + 0.2` as `0.3`. Infinity and NaN are refused |
| `DateTimeInterface` | `Y-m-d H:i:s`, with `.u` when there are microseconds. The wall-clock time as given: **the time zone is not converted**, because which zone a column holds is your decision |
| a stream resource | a large object, for binary data — on SQL Server with the driver's binary encoding, which a `VARBINARY` column needs |
| `Stringable` | its string |

Anything else is refused, named by parameter position and type, never by value.

## Transactions

A transaction is the application's boundary, not each repository method's:

```php
$connection->transaction(function (Connection $db) use ($invoice): void {
    $invoices->store($invoice);
    $entries->post($invoice);
    $payments->record($invoice);
});
```

The callback either finishes, and everything is committed, or throws, and
nothing is written.

**Nesting means what it looks like.** PDO has no nested transactions — a second
`beginTransaction()` throws or is ignored, and the first `commit()` writes
everything. So `begin()` uses **savepoints** beyond the first level:
`SAVEPOINT`/`RELEASE`/`ROLLBACK TO` on MySQL, PostgreSQL and SQLite, and
`SAVE TRANSACTION`/`ROLLBACK TRANSACTION` on SQL Server, which has no release.
Any other driver refuses to nest, with a sentence rather than a syntax error.

> **One caveat, and it is inherent to nested transactions everywhere.** Rolling
> back to a savepoint undoes the inner work and leaves the outer transaction
> open. So if the calling code swallows the exception, the outer transaction
> still commits, with the inner work gone. Catch deliberately or not at all.

When something goes wrong inside the machinery itself:

| What happens | What you get |
|---|---|
| The callback throws, and the rollback fails too | The **callback's** exception. The connection is closed, so the database discards the transaction with the session |
| A rollback fails at an inner level | The connection is closed. The outer levels are *lost*: every statement and commit is refused until each has rolled back, so an outer callback that swallowed the failure cannot go on writing in autocommit mode |
| The callback leaves a level open, or finishes one it did not begin | Everything since the callback began is rolled back, and the imbalance is reported |
| `disconnect()` inside a transaction | The connection closes (the work is discarded), then an exception names the connection and the depth. `disconnectAll()` closes every connection before reporting |

### Isolation levels and retrying

```php
$connection->transaction($callback, isolation: IsolationLevel::Serializable, retries: 3);
```

**`isolation:`** runs the transaction at `ReadUncommitted`, `ReadCommitted`,
`RepeatableRead` or `Serializable`. Each dialect writes the level where its
database needs it:

| Database | Where the level is set | What else |
|---|---|---|
| MySQL, MariaDB | before `BEGIN` | applies to that transaction only |
| PostgreSQL | first statement inside | `ReadUncommitted` runs as `ReadCommitted`, which is stricter, never weaker |
| SQL Server | before `BEGIN` | lasts for the session, so it is put back to `READ COMMITTED` afterwards. If that fails, the session is closed |
| SQLite | nowhere | only `Serializable` exists; every other level is refused |

**A level a database cannot give is refused before anything runs**, and a
different level is never quietly substituted: code that asked for
`READ COMMITTED` may be relying on not blocking.

The level and the retries belong to the **outermost** transaction. A nested
`transaction()` given either is refused.

**`retries:`** runs the whole transaction again, from a clean state, when it
fails with a **deadlock or a serialization failure** — SQLSTATE `40001` (which
MySQL and SQL Server also use for their deadlocks), `40P01` on PostgreSQL, or
MySQL's error 1213. Nothing else is retried: not a constraint violation, not a
lock-wait timeout, not a lost connection, and not an exception of your own.

Between attempts there is a short, growing, jittered pause: 10 ms, 20 ms, 40 ms
and so on, up to 200 ms. The last failure is thrown once the retries are spent.
`$connection->isRetryable($e)` makes the same judgement for code that manages
its own retries.

> **With retries, the callback may run more than once.** Its database work is
> rolled back between attempts, but nothing else is. An email sent, a card
> charged, a file written or a job queued inside the callback happens once per
> attempt. Do those after `transaction()` returns.

## Watching statements and transactions

Inside an application, each connection fires these hooks:

| Hook | Arguments | When |
|---|---|---|
| `database.query.failed` | `DatabaseException $failure, Connection $connection` | the database refused a statement |
| `database.transaction.committed` | `Connection $connection` | the outermost transaction committed |
| `database.transaction.rolled_back` | `Connection $connection, ?Throwable $cause` | the outermost transaction was rolled back: `$cause` is the failure that ended a `transaction()`, or null after a `rollBack()` |
| `database.transaction.retrying` | `Throwable $failure, int $attempt, Connection $connection` | `transaction(retries:)` is about to try again; `$attempt` is 2 for the first retry |

```php
$hooks->add('database.transaction.committed', function (Connection $connection) use ($outbox): void {
    $outbox->flush();
});
```

Savepoints are not announced: releasing one commits nothing, and rolling one
back leaves the transaction open.

**Each hook fires once what it reports has already happened**, so a listener
cannot change it:

- a listener that throws after a commit does not undo the commit;
- nothing a listener throws causes a transaction to be retried;
- a listener that throws on `query.failed`, or on a rollback inside
  `transaction()`, is ignored, so the caller still gets the database's failure.

The failure carries the SQL and how many values were bound, never the values.

**The database layer itself knows nothing about hooks.** Outside an application,
`listen()` on a connection or on the `ConnectionManager` receives the same
events, without the `database.` prefix:

```php
$connections->listen(function (string $event, mixed ...$arguments): void { /* ... */ });
```

`observe()` gets a callback after every statement, including a failed one. It
receives:

1. the SQL;
2. the nanoseconds it took;
3. the connection's name;
4. how many values were bound;
5. how many rows the statement returned or changed, or null when that was not
   known at the time (a failure, a cursor, or `run()`).

An observer may declare fewer parameters and ignore the rest. The bound values
are never passed. The profiler and the
[slow-query warning](observability.md#slow-queries-profiling-or-not) are built
on it.

## The query builder

For reads that are SQL and should look like it, `table()` gives a builder:

```php
$invoices = $connection->table('invoices AS i')
    ->select('i.id', 'i.total', 'i.customer_id AS customer')
    ->whereNull('i.cancelled_at')
    ->where(fn (QueryBuilder $q) => $q->where('i.status', 'open')->orWhere('i.total', '>', 1000))
    ->whereIn('i.region', $regions)
    ->orderByDesc('i.id')
    ->limit(50)
    ->get();
```

| Building | Running |
|---|---|
| `select(...)` — names, `t.*`, `x AS y`, or a `RawExpression` | `get()` — every row |
| `where` / `orWhere` — `(col, value)`, `(col, op, value)`, a closure group, or a `RawExpression` | `first()` — one row or `null` |
| `whereNull` / `whereNotNull` | `cursor()` — a generator, one row at a time |
| `whereIn` / `whereNotIn` — an empty list matches nothing / excludes nothing | `count()` — ignores order, limit and offset |
| `whereBetween` / `whereNotBetween` — inclusive | `exists()` — reads at most one row |
| `orderBy(col, 'asc'\|'desc')`, `orderByDesc`, `limit`, `offset` | `compile()` — the SQL and bindings, without running them |

Five things to know:

- **Building runs nothing.** The connection opens when a terminal method runs.
- **Builders are immutable.** Every method returns a new builder, so a base
  query can be narrowed two ways without either seeing the other. A grouping
  closure must **return** the builder it built; one that returns nothing is
  refused.
- **Values are bound; names are checked.** A value never reaches the SQL string.
  A table or column name is checked part by part (`sales.orders`,
  `o.total AS amount`) against the identifier pattern; an operator must be one
  of `= != <> < <= > >= like not like`; a direction must be `asc` or `desc`.
  Nothing else is accepted, so a name or a sort order taken from a request fails
  instead of becoming SQL. **Map request input onto names you chose; never pass
  it through.**
- **`where('col', null)` is refused.** `= NULL` is never true in SQL, and a
  query that silently matches nothing is worse than an exception pointing at
  `whereNull()`.
- **`RawExpression` is the escape hatch**, and the only one: hand-written SQL
  with its own bindings, in parentheses when it is a condition. It is trusted
  exactly as far as the code that wrote it.

The builder is SQL-only. `Data\Query` is a different thing: AND only, no joins,
the same on every kind of storage. A repository method uses the builder when a
read is relational, the same way it would use raw SQL.

### Joins, grouping and aggregates

```php
$revenue = $db->table('customers AS c')
    ->select('c.region', Aggregate::count(as: 'orders'), Aggregate::sum('o.total', as: 'revenue'))
    ->join('orders AS o', 'o.customer_id', '=', 'c.id')
    ->leftJoin('refunds AS r', fn (JoinClause $j) => $j->on('r.order_id', '=', 'o.id')->where('r.status', 'settled'))
    ->whereNull('r.id')
    ->groupBy('c.region')
    ->having(Aggregate::sum('o.total'), '>', 10000)
    ->orderByDesc('revenue')
    ->get();

$db->table('orders')->where('status', 'paid')->sum('total');   // also avg(), min(), max()
```

- **`join()`, `leftJoin()` and `rightJoin()`.** The four-argument form compares
  two columns. A closure gets a `JoinClause`: `on()` and `orOn()` compare
  columns, `where()` compares a column with a bound value, and the closure must
  return the clause. A join with no condition is refused, because it would pair
  every row with every other.
- **`rightJoin()` needs `Capability::RightJoin`.** SQLite is not counted as
  having it: it arrived in 3.39, and older builds are still shipped. A left join
  with the tables swapped asks the same question.
- **`whereColumn('updated_at', '>', 'created_at')`** compares two columns. The
  second is a name, never a value.
- **`Aggregate`** is COUNT, SUM, AVG, MIN or MAX of a checked column, optionally
  with `as:`. Select it, use it in `having()`, or ask for it directly with
  `sum()`, `avg()`, `min()` and `max()`. Anything more elaborate is a
  `RawExpression`.
- **`having()` takes an `Aggregate`, a grouped column or a `RawExpression` — not
  a selected alias.** PostgreSQL and SQL Server cannot see an alias in HAVING,
  so repeat the aggregate. `orderBy('revenue')` may name the alias: every
  database accepts that in ORDER BY.
- **`count()` on a grouped query counts the groups**, by counting the rows of
  the grouped query. `sum()` and the others are refused on a grouped query,
  where they would mean one value per group: select the aggregate and use
  `get()`.
- **Numbers come back as the database returns them.** A SUM of an integer column
  is an int, while a SUM or AVG of a DECIMAL is a string, so no digit of money is
  lost to a float. No matching rows gives `null`, as in SQL, not zero.
- **An average keeps its fraction on every database.** SQL Server alone averages
  an integer column in integers (3 and 4 average to 3), so its dialect writes
  `AVG(column * 1.0)`, which comes back as a decimal string, as on MySQL and
  PostgreSQL.
- Joins and groupings are for reads. A write that carries either is refused.

### Writing through the builder

```php
$id = $db->table('invoices')->insert(['customer_id' => 7, 'total' => 120.5], 'id');
$db->table('lines')->insertMany($lines);                        // how many were written
$db->table('stock')->where('sku', $sku)->update(['quantity' => new RawExpression('quantity - ?', [2])]);
$db->table('sessions')->where('expires_at', '<', $now)->delete();
$db->table('stock')->upsert($rows, uniqueBy: ['sku'], update: ['quantity']);
```

- **`insert($row, 'id')` returns the key the database generated**, when you name
  the key column. On PostgreSQL that comes from `RETURNING` and on SQL Server
  from `OUTPUT INSERTED`, in the same statement; on MySQL and SQLite from the
  connection's last-insert id, which those databases keep per statement. Without
  a key column, `insert()` returns null.
- **`update()` and `delete()` refuse to run without a condition.** A forgotten
  `where()` throws instead of rewriting the table. `updateAll()` and
  `deleteAll()` say "every row" in so many words.
- **A write refuses what only a read can honour:** a column list, an order, a
  limit or an offset — and conditions, for an insert. `UPDATE … LIMIT` works
  only on MySQL, and ignoring the limit elsewhere would write more rows than
  were asked for. To write "the first ten", select their keys, then `whereIn()`
  them.
- **Every row of `insertMany()` or `upsert()` names the same columns**, in any
  order. A missing column is refused rather than filled with null, which would
  override the column's default without anyone deciding to. Rows are split to
  fit each database's placeholder limit. Wrap the call in `transaction()` when
  it must be all or nothing.
- **A value may be a `RawExpression`** in `insert()` and `update()`, but not in
  the many-row writes, which are split by counting placeholders.
- **`upsert()` is offered only where the database has one** —
  `Capability::Upsert` on MySQL, PostgreSQL and SQLite. SQL Server's `MERGE` is
  a different statement with different locking, so it is refused rather than
  faked. `update` defaults to every column outside the key; `[]` leaves an
  existing row alone. MySQL judges a conflict by whichever unique key the row
  collides with, whatever `uniqueBy` says. PostgreSQL refuses a statement that
  names the same key twice.
- **The counts are the database's.** MySQL counts only the rows whose values
  actually changed.
- **The table in a write is named plainly.** A schema prefix (`sales.orders`) is
  fine, but an alias is refused: the databases disagree about where an alias
  goes in UPDATE and DELETE.

**Locking rows for a read-modify-write:**

```php
$db->transaction(function (Connection $db) use ($sku): void {
    $row = $db->table('stock')->where('sku', $sku)->lockForUpdate()->first();
    $db->table('stock')->where('sku', $sku)->update(['quantity' => $row['quantity'] - 1]);
});
```

`lockForUpdate()` holds the rows it reads until the transaction ends, so no
other transaction changes or locks them in between. It is `FOR UPDATE` on MySQL
and PostgreSQL and `WITH (UPDLOCK, ROWLOCK)` on SQL Server. SQLite writes
nothing: a write there locks the whole database, one writer at a time.

Outside a transaction the lock would end with its own statement, so running one
is refused. A grouped query is refused too, since each result row stands for
many. The database session store is built on it.

## Creating tables

A table is described once, with chained methods, and each connection's grammar
writes it in its own dialect:

```php
$connection->tables()->create('invoices', static function (Table $table): void {
    $table->id();
    $table->bigInteger('customer_id')->index();
    $table->string('number', 30)->unique();
    $table->decimal('total', 12, 2)->default('0.00');
    $table->boolean('paid')->default(false);
    $table->dateTime('issued_at')->nullable();
    $table->timestamps();
    $table->foreign('customer_id')->references('customers')->onDelete('cascade');
});

$connection->tables()->drop('invoices');
```

| Builder | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `id()` | `BIGINT AUTO_INCREMENT PRIMARY KEY` | `BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `BIGINT IDENTITY(1,1) PRIMARY KEY` |
| `integer` / `bigInteger` | `INT` / `BIGINT` | `INTEGER` / `BIGINT` | `INTEGER` | `INT` / `BIGINT` |
| `decimal(p, s)` | `DECIMAL(p,s)` | `NUMERIC(p,s)` | `NUMERIC` | `DECIMAL(p,s)` |
| `string(n)`, 255 by default | `VARCHAR(n)` | `VARCHAR(n)` | `TEXT` | `NVARCHAR(n)` |
| `text` | `LONGTEXT` | `TEXT` | `TEXT` | `NVARCHAR(MAX)` |
| `boolean` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` | `BIT` |
| `date` / `dateTime` | `DATE` / `DATETIME(6)` | `DATE` / `TIMESTAMP(6)` | `TEXT` | `DATE` / `DATETIME2(6)` |
| `binary` | `LONGBLOB` | `BYTEA` | `BLOB` | `VARBINARY(MAX)` |

- **Modifiers:** `nullable()`, `default()`, `unique()` and `index()`. A column
  must hold a value unless it says `nullable()`. `timestamps()` adds nullable
  `created_at` and `updated_at`. For an index over several columns, use
  `$table->index('a', 'b')` or `$table->unique('a', 'b')`.
- **A table whose key is not a counted id** says so on the column:
  `$table->string('code', 2)->primary()`. There is one key per table, `id()` or
  `primary()`. It cannot be NULL, and it cannot be added to a table that already
  exists.
- **A key that points at an `id()` is a `bigInteger`.** `id()` is a signed
  64-bit integer everywhere, and MySQL refuses a foreign key whose type differs
  from the column it points at. The actions are `cascade`, `restrict`,
  `set null` and `no action`. SQL Server has no `RESTRICT`, so it writes
  `NO ACTION`, which refuses the same change.
- **Every database stores Unicode.** MySQL tables are created
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`: MyISAM would ignore the foreign keys,
  and a database whose default is `latin1` could not hold most of the world's
  text. SQL Server's strings are `NVARCHAR` for the same reason. Give MySQL
  connections `charset=utf8mb4` in the DSN as well.
- **A unique column may be empty in any number of rows**, on every database. SQL
  Server alone treats NULLs as equal in a unique index, so its index leaves out
  the rows holding NULL.
- **A string default is quoted by the driver**, because a DEFAULT is written into
  the statement and cannot be bound. On SQL Server it is written `N'...'`, since
  a plain literal is read in the server's code page and loses what it cannot
  hold. A float default is refused: write `'0.50'`, which every database reads
  into a decimal exactly.
- **What some database cannot do is refused before anything runs:** indexing or
  defaulting `text` and `binary` columns, indexing a string longer than 768
  characters (MySQL's key limit), a decimal outside 1 to 38 digits, a string
  outside 1 to 4000 characters, `set null` on a column that cannot be null. A
  driver with no dialect of its own creates no tables at all.
- **SQLite's own behaviour:** a string's length is not enforced; a decimal is
  stored as a number, so `12.50` reads back as `12.5`; and foreign keys are
  enforced only after `PRAGMA foreign_keys = ON` on that connection.
- **A table and its indexes are separate statements.** On MySQL, which has no
  `TransactionalDdl`, a failing index leaves the table behind.
- **`$tables->raw($sql, 'pgsql')`** runs hand-written SQL, and only on the
  drivers it names. On any other it refuses and runs nothing. It is for what the
  builder does not describe.

Every column type, default, key and index above is created, filled, read back
and dropped on each database by `DialectConformanceTest`.

### Changing tables

`alter()` takes a description of the change only. The column methods add
columns, and five more take away:

```php
$connection->tables()->alter('invoices', static function (Table $table): void {
    $table->string('reference', 40)->nullable()->unique();
    $table->integer('copies')->default(1);
    $table->renameColumn('number', 'code');
    $table->dropIndex('customer_id');      // or dropUnique(), over the same columns
    $table->dropForeign('account_id');
    $table->dropColumn('notes', 'legacy_total');
});
```

- **The statements run in a fixed order**, whatever order you write the change
  in: foreign keys, indexes and columns are dropped first, then columns are
  renamed, then columns, indexes and foreign keys are added. So a column's index
  can be dropped in the same change as the column, and a new index can name a
  renamed column.
- **Drop a column's indexes and keys with it.** MySQL and PostgreSQL would take
  them along, but SQLite and SQL Server refuse to drop an indexed column.
  Indexes and keys are found by the name the builder gave them, made from the
  table and the columns. A renamed column's index keeps its old name, so drop it
  by the old column name.
- **A column added to a table with rows needs a value for them:** `nullable()`
  or a default. The rows already there get the default. A new `id()` is refused.
- **SQL Server:** a column with a default is dropped along with its default,
  whose name the server made up. A new unique index on a column already in the
  table leaves out NULLs, since the change does not say whether the column can
  hold them.
- **What SQLite cannot do is refused, with nothing run.** SQLite cannot drop a
  foreign key, or add one to a column that is already there. A foreign key on a
  column added in the same change is written with the column, which SQLite
  allows when the column is nullable and has no default. The builder never
  rebuilds a table behind your back; that would copy every row and lose whatever
  the builder does not describe. `DROP COLUMN` needs SQLite 3.35.
- **Renaming a column needs MySQL 8.0 or MariaDB 10.5.2.** Before those, MySQL
  could rename a column only by restating its whole definition, which a rename
  does not know. On an older server the change is refused with nothing run;
  write the `CHANGE` yourself with `$tables->raw(..., 'mysql')`.
- **Everything is written before the first statement is sent**, so a refusal
  runs nothing. Each change is still its own statement, so on MySQL, which has
  no `TransactionalDdl`, one that fails leaves the ones before it made.

Each of these changes is run against a table that already holds rows, on each
database, by `DialectConformanceTest`.

## Migrations and seeders

A *migration* is one change to the structure of the database. A *seeder* is rows
a module needs to start with.

A module keeps migrations in `Database/Migrations/` and seeders in
`Database/Seeders/`, one file each. **Nothing registers them: the folder is
enough.** A disabled module's are skipped.

```php
// modules/Billing/Database/Migrations/2026_09_19_120000_create_invoices.php
return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('invoices', static function (Table $table): void {
            $table->id();
            $table->bigInteger('customer_id')->index();
            $table->decimal('total', 12, 2)->default('0.00');
            $table->foreign('customer_id')->references('customers');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('invoices');
    }
};
```

A file returns a `Migration`, which has `up(Tables)`, or a `Reversible`, which
adds `down(Tables)`. Its name is `YYYY_MM_DD_HHMMSS_what_it_does.php`, in lower
case, and its id is `<module id>:<file name>`. **There is no `make:migration`** —
create the file yourself.

| Command | Does |
|---|---|
| `migrate` | Runs every pending migration, as one batch. `--pretend` prints the SQL each would send to this database and runs nothing; hand-written SQL is marked `[raw]`. |
| `migrate:status` | Lists every migration, whether it ran and in which batch. A recorded migration whose file has gone is called out. |
| `migrate:rollback` | Undoes the last batch, or `--batches=N`, newest first. Needs `--force` in production. |
| `db:seed` | Runs every seeder, or one module's with `--module=Billing`. Needs `--force` in production. |

All four take `--connection=<name>`. **Migrations are a deployment step**: give
that connection an account allowed to create tables, which the application's own
account should not be. Nothing creates a table during a request.

What to know about a run:

- **The framework's own tables come first**, and only for the stores in use.
  While `session.store` is `database`, that is the session table, recorded as
  `framework:2026_09_19_000000_create_sessions`. See [Sessions](sessions.md).
- **Order.** Modules run in the order they load: `Shared` first, then each
  module after the modules it `requires()`. Within a module, files run in name
  order. A migration whose foreign key names another module's table belongs to a
  module that requires that one.
- **Once.** Each migration that ran is a row in `database.migrations.table`
  (`migrations` by default), with its module, batch, position and time. That
  table is created, with the builder, on the first run.
- **All or nothing, where the database can.** On a database with
  `TransactionalDdl` (PostgreSQL, SQLite, SQL Server) a migration and its row
  commit together, so a failure leaves neither. MySQL commits each change of
  structure as it runs, so a migration that fails there leaves what ran before
  the failure, is not recorded, and the error says so.
- **Checked before anything runs.** Every pending file is loaded first, so a
  badly named file, or one returning something else, stops the run with nothing
  done. A rollback is refused before it starts if any migration in its range has
  no `down()` or no file.
- **One run at a time.** A run holds a lock in the database for its whole
  length: `GET_LOCK` on MySQL, an advisory lock on PostgreSQL, `sp_getapplock`
  on SQL Server. SQLite, with one writer, needs none. Two deployments starting
  together, on different machines, cannot run the same migration twice. A run
  waits up to ten seconds for the lock, then stops.
- **Failures say what happened.** The message names the migration, the database
  and what was kept. The database's own error follows on a `Cause:` line — in
  full with `APP_DEBUG=1`, and otherwise by its class only, because it can carry
  a credential.
- **`$tables->raw($sql, 'pgsql')`** is the escape hatch, for a data fix or a
  feature the builder does not describe. It runs only on the drivers it names.

### Seeders

A seeder returns a `Seeder`, whose `run(Connection $db)` writes through the
query builder:

```php
// modules/Billing/Database/Seeders/currencies.php
return new class implements Seeder {
    public function run(Connection $db): void
    {
        if (!$db->table('currencies')->where('code', 'BDT')->exists()) {
            $db->table('currencies')->insert(['code' => 'BDT', 'name' => 'Taka']);
        }
    }
};
```

- **Seeders run in module order, then name order.** Name a file
  `01_currencies.php` when one of a module's seeders needs another to have run.
- **Nothing records that a seeder ran.** `db:seed` runs every seeder every time,
  so each looks before it inserts, and running one twice changes nothing.
- **Each seeder runs in its own transaction.** A failing seeder leaves none of
  its rows; the seeders before it have committed.

In a test, `$this->migrate($app)` runs every module's migrations on the booted
application, so the test has the same tables production has.

## Running the data layer on it

`SqlSource` implements `DataSource`, so every repository, query, read model,
relation and page from [Repositories and queries](data.md) runs against a real
database **unchanged**. Switching is one factory in a module:

```php
$services->singleton(DataSource::class, static function (Container $c): DataSource {
    $connections = $c->get(ConnectionManager::class);

    return $connections->isConfigured()
        ? new SqlSource($connections->connection())
        : new ArraySource();
});
```

That is the only place in the application that knows which kind of storage it
has. The framework does not bind `DataSource` itself — which source an
application reads through is its decision, not the framework's, and that is
architecture-tested too.

`SqlSource::fetch()` returns a **cursor**, so `Query::stream()` and
`Query::chunk()` genuinely hold one row at a time rather than a whole table.

## Testing against a database

The database tests are real integration tests against SQLite in memory — real
PDO, real prepared statements, real savepoints — costing about a millisecond
each and needing nothing installed.

`tests/Feature/SqlSourceTest.php` runs the same repository against `ArraySource`
and `SqlSource` and asserts the answers are identical, which is the `DataSource`
claim stated as a test rather than as a paragraph.

SQLite agreeing with a dialect proves nothing about the other databases, so
`DialectConformanceTest` runs each dialect on its own database too: MySQL,
PostgreSQL or SQL Server, whichever `DB_TEST_*_DSN` names (see
[Development](../contributing/development.md#testing-against-database-servers)).
CI runs all three. MySQL (as MariaDB) and PostgreSQL are also run where the
framework is developed; SQL Server is not, so CI is the only place its dialect
meets a real server.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| "no such table" | The migrations have not run on this database | `php laika migrate`; `migrate:status` shows what is pending |
| Everything saved is gone next request | No database is configured, so the memory source is in use | Set `DB_DSN` |
| A rename is refused on MySQL or MariaDB | `RENAME COLUMN` needs MySQL 8.0 or MariaDB 10.5.2 | Upgrade, or write the `CHANGE` with `$tables->raw(…, 'mysql')` |
| `update()` or `delete()` throws | It has no `where()`, and will not rewrite a table by accident | Add a condition, or call `updateAll()` / `deleteAll()` |
| `where('col', null)` throws | `= NULL` is never true in SQL | Use `whereNull()` |
| A sort column from a request throws | Names are checked against the identifier pattern | Map request input onto names you chose |
| A migration run waits, then stops | Another run holds the lock | Wait for the other deployment to finish |
| `upsert()` is refused | SQL Server has no equivalent statement | Write the `MERGE` yourself, or insert and update |
| A callback ran twice | `retries:` retried a deadlock | Move emails, charges and queued jobs out of the callback |

## Why it works this way

### The injection boundary is one file wide

No database accepts a parameter where a column name goes, so table and column
names are the one part of a statement built by interpolation. All of that lives
in `Grammar` and its dialects, and every part of a name passes one check first:

```
/^[A-Za-z_][A-Za-z0-9_]*$/
```

A name is split on its dots (`sales.orders` is two parts; a table may have two,
a column three) and on `AS` for an alias, and each part is checked and quoted on
its own. Anything else — a quote, a space, a comment, a semicolon, a function
call, an empty part — is refused with an exception naming it.

Names reach the Grammar from repository declarations rather than from requests.
The check is what keeps that true on the day somebody passes a sort column
straight from a query string. An architecture test asserts that no other file in
`engine/Database/` builds SQL at all.

Limits and offsets are written in rather than bound: they are typed `int` in PHP
by the time they arrive, so there is nothing to inject, and binding them is the
one thing several drivers get wrong.

### Dialects

Each connection speaks its database's dialect, picked from the driver in its DSN
by `Grammar::for()`:

| Driver | Dialect | Differs in |
|---|---|---|
| `mysql` | `MySqlGrammar` | backtick quoting; 65535 placeholders per statement |
| `pgsql` | `PostgresGrammar` | 65535 placeholders. Placeholders stay `?`, which PDO rewrites into PostgreSQL's numbered form |
| `sqlite` | `SqliteGrammar` | nothing: standard quoting and paging, 999 placeholders |
| `sqlsrv` | `SqlServerGrammar` | `[bracket]` quoting; `OFFSET … FETCH` paging, with `ORDER BY (SELECT NULL)` when a query has no order of its own; `SAVE TRANSACTION` savepoints; 2000 placeholders |
| anything else | `Grammar` | standard SQL, and no capabilities claimed |

What a database can do at all is asked of the connection:

```php
if ($connection->supports(Capability::Savepoints)) { ... }
```

| Capability | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `Savepoints` — transactions nest | yes | yes | yes | yes |
| `Returning` — an INSERT hands back its generated key | no | yes | no¹ | yes (`OUTPUT`) |
| `Upsert` — insert or update by a unique key | yes | yes | yes | no |
| `RightJoin` — RIGHT JOIN | yes | yes | no² | yes |
| `TransactionalDdl` — creating and dropping tables can be rolled back | no | yes | yes | yes |

¹ SQLite has had `RETURNING` since 3.35, but some distributions still ship older
builds. SQLite's last-insert id is correct per statement anyway.
² From 3.39, which is newer than some distributions ship.

A capability is added with the feature that needs it, never ahead of one. A test
holds this table for every dialect, so a new capability cannot be added without
deciding it for each database.
