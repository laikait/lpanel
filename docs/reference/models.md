# Models

A *model* is one record in your domain — a customer, an invoice — together with
the behaviour that keeps it valid.

It is **not** an active record. There is no `save()`, no `delete()`, no static
`find()`, no query builder on it, and no lazy relationship property. Saving is
the [repository's](data.md) job.

## Write a model

```php
final class Customer extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $email,
        private ?int $ownerId = null,
        private bool $active = true,
    ) {}

    public function identity(): ?int { return $this->id; }

    public function deactivate(): void { $this->active = false; }
}
```

Ordinary typed properties, a constructor that can refuse bad state, and domain
methods instead of setters. `identity()` is the only method the base class
requires.

What the engine adds is the part persistence cannot work out for itself:
tracking changes, loading related records, and keeping one object per row.

## Change tracking

| Call | Does |
|---|---|
| `markClean()` | take a snapshot of the declared properties |
| `changes()` | what has changed since that snapshot |
| `isDirty($name = null)` | whether anything, or one property, has changed |
| `isNew()` | whether it has ever been clean |

A repository writes the changed columns rather than every column, and it learns
that from `deactivate()` without a single setter: tracking reads the properties
rather than intercepting writes.

A model that has never been clean reports all of its attributes, so `changes()`
serves an insert and an update alike.

## Encrypted attributes

```php
public function __construct(
    private ?int $id,
    private string $email,
    #[Encrypted] private ?string $taxNumber = null,
) {}
```

The repository encrypts the value as it writes the row, and hydration decrypts
it as it reads one, through [`Encrypter`](security.md#encryption) and `APP_KEY`.
In the model it is plain text; in the database it is a `v1.…` token.

- **The purpose is `<Model class>.<property>`**, so a token copied into another
  column, or into another model's column, does not decrypt there.
- **Only `string` and `?string` properties.** `null` is stored as `null`.
- **A value that does not decrypt is a `ModelException`**, never a quiet `null`:
  another key (put the old one in `APP_PREVIOUS_KEYS`), a changed token, or plain
  text left over from before the attribute was added. Encrypt existing rows
  before you deploy the attribute.
- **`persist()`, `insertMany()` and `updateWhere()` encrypt;** a raw query
  through the connection does not.
- **The column cannot be searched, sorted or made unique**: two encryptions of
  one value differ. Keep a separate hash column when you need a lookup. The
  token is about 1.4 times the value plus 56 characters, so size the column for
  it.

## Related records are loaded on purpose

A `Relation` is a declaration and nothing more. Loading is written out where you
can see it:

```php
$relations->declare(Customer::class,
    Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'));

$customers = $catalog->all();                                      // one query
$owners = $users->findAll($relations->keysFor($customers, 'owner')); // one more
$relations->link($customers, 'owner', $owners);                    // none
```

`Relation::one(...)` and `Relation::many(...)` are the two kinds.

`$customer->related('owner')` returns what was attached, and **throws if nothing
was**. There is no lazy loader to trigger by accident, so a loop over ten
thousand customers cannot quietly become ten thousand queries.

A relation that was loaded and found nothing is attached as `null` or an empty
collection, so "empty" and "never loaded" stay different answers.
`hasRelated()` asks which one you have.

## One object per row

`ModelManager` builds models from rows by matching columns to the constructor's
**parameter names**, converting only where the conversion is unambiguous — which
matters, because a driver may hand every column back as a string.

Within one unit of work, the same row built twice is the same object, so a
change made through one reference is visible through the other.

> **The identity map is memory.** A long-running worker must call
> `ModelManager::flush()` between units of work, or state from one job is
> visible in the next.

## Read models

Domain models are deliberately not serialisable: a domain entity is not an API
representation. A list endpoint projects into a read model instead:

```php
final class CustomerListRecord extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}
}
```

Three columns, no tracking, no relations — and `Query::into()` selects only
those columns. The fields in the payload are the ones somebody chose to put
there.

## Where models live

Business models never live in `engine/Model/`, which holds infrastructure only.
They belong to the module that owns the capability, or to `modules/Shared/` when
more than one module genuinely needs them. Both rules are enforced by tests.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A property is never filled | Column names must match the constructor's parameter names exactly, including case | Rename one or the other |
| `related()` throws | Nothing was attached; there is no lazy loading | Load with `keysFor()` and `link()` first |
| A worker sees stale data | The identity map holds rows for the life of the process | `ModelManager::flush()` between jobs |
| An update writes every column | The model was never `markClean()`ed, so it counts as new | That is correct for an insert |
| "does not decrypt" on load | An `#[Encrypted]` column holds plain text, or was written with another `APP_KEY` | Encrypt old rows; list the old key in `APP_PREVIOUS_KEYS` |
| A model cannot be turned into JSON | Domain models are not serialisable on purpose | Project into a `ReadModel` |
