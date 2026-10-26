# Schemas

A *schema* describes the shape of structured data: a request body, a response,
a configuration block, an API contract. It is not tied to a model and not tied
to a table.

Use one to check what arrived from outside, and to shape what you send back.

## Declare a schema

```php
final class CustomerSchema
{
    public static function input(): Schema
    {
        return Schema::of('customer.input',
            Field::string('name')->length(1, 120),
            Field::string('email')->check(
                'an email address',
                static fn (mixed $v): bool => \is_string($v)
                    && \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false,
            ),
        );
    }

    public static function resource(): Schema
    {
        return self::input()->with(Field::int('id'))->named('customer.resource');
    }
}
```

| Field | For |
|---|---|
| `Field::string`, `::int`, `::float`, `::bool` | one value |
| `Field::object($name, $schema)` | a nested object |
| `Field::listOf($name, $element)` | a list of one kind of field |
| `Field::collection($name, $schema)` | a list of objects |

Fields are immutable values, so one declared once can be reused across schemas.
`Schema::with()` adds fields to a copy, and `named()` renames it — which is how
`resource()` above is `input()` plus an id.

**Typed constructors rather than `'required|string|max:255'`.** A rule string is
shorter to type and worse in every other way: nothing checks it, your editor
cannot complete it, a typo is found by a user, and it needs a parser for a
second language inside the first.

Here a mistake is a PHP error — and a constraint that cannot mean anything, such
as a `length()` on an integer, is refused **while the schema is built**, not on
the first request that happens to exercise that field.

## Required, optional, nullable

**Required is the default.** A field you declared and forgot to mark fails
loudly rather than going quietly missing.

| Modifier | Means |
|---|---|
| `optional()` | the key may be absent |
| `default($value)` | absent means this value |
| `nullable()` | the value may be `null` |

`null` and absent are different questions: `nullable()` governs one,
`optional()` and `default()` the other.

## Constraints

| Constraint | Applies to |
|---|---|
| `range($min, $max)` | numbers |
| `length($min, $max)` | strings |
| `size($min, $max)` | lists and collections |
| `pattern($regex, $expectation = null)` | strings |
| `oneOf([...])` | any field |
| `check($expectation, $predicate)` | anything else |

The set is closed on purpose. There is no `email`, `url`, `uuid` or `date` rule,
because that list never ends and every entry is an opinion somebody disagrees
with. `check('an email address', …)` is one line, and the engine keeps no view
on what a valid postcode looks like in your country.

## The three operations

| Call | Does |
|---|---|
| `validate($data)` | collects everything wrong, and throws nothing |
| `deserialize($data)` | data coming **in**: converts, applies defaults, **drops unknown keys**. A failure is the sender's fault |
| `serialize($data)` | data going **out**: the same shaping, but a failure means the application broke its own promise, so it is reported as a bug |

Validation does not stop at the first problem, and every error carries a path,
so nested data reports `address.postcode` and `contacts.2.email` rather than
"validation failed":

```json
{"error": {"status": 400,
  "fields": {"name": ["is required"], "email": ["must be an email address"]},
  "expected": {"name": {"type": "string", "min": 1, "max": 120}, …}}}
```

The `expected` block is `Schema::describe()` — your contract as plain data,
derived from the same declaration that enforced it, so the documentation cannot
drift from the behaviour.

## `deserialize()` returns an array

Not an object. That is what keeps a schema from becoming a model factory:

```php
$attributes = CustomerSchema::input()->deserialize($request->json());
$customer = $models->hydrate(Customer::class, $attributes);
```

Neither side knows about the other — and dropping unknown keys means a client
cannot set a field you did not declare.

## This is not a form request

It is not a base class anyone extends, it is not resolved out of a handler's
signature, and it throws no status codes. Your handler calls a schema where it
chooses and decides for itself what a failure means.

The schema layer cannot reference `Http`, `Routing`, `Dispatch`, `Model`,
`Container` or `Module` at all — an architecture test enforces that, so it
cannot grow into a form request later.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A field is reported missing that you did send | Required is the default, and unknown keys are dropped | Check the spelling against the declaration |
| The schema throws while being built | A constraint cannot apply to that type, e.g. `length()` on an int | The message names the field |
| A value you sent is not in the result | `deserialize()` drops keys the schema does not declare | Declare the field |
| `serialize()` throws | The application produced data that breaks its own contract | Fix the data; this is a bug, not bad input |
| `null` is refused | `nullable()` and `optional()` are different | Add the one you meant |
