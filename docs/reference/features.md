# Feature flags

Switch code on for some people before everyone: staff first, then a tenth of
customers, then all of them, without a deployment in between.

```php
// config/features.php
return [
    'new-checkout' => ['roles' => ['staff'], 'percent' => 10],
    'dark-mode'    => true,
    'old-search'   => false,
];
```

```php
public function __construct(private readonly Features $features) {}

if ($this->features->active('new-checkout')) { … }          // the current identity
$this->features->active('new-checkout', $someoneElse);      // or a given one
```

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

```twig
{% if feature('new-checkout') %}…{% endif %}
```

<!-- {% endraw %} -->

```php
$routes->get('/checkout/v2', NewCheckout::class)->meta(['feature' => 'new-checkout']);   // 404 while off
```

## Rules

A flag is `true`, `false`, or an array of rules. **Any one rule** switches it on:

| Rule | On for |
|---|---|
| `accounts` | these identity ids |
| `roles` | identities holding any of these roles |
| `percent` | that share of signed-in identities, 0 to 100 |

**A percentage is stable.** Who is in is decided by a hash of the flag's name
and the identity's id:

- the same person stays in or out on every request and on every server;
- raising the number only adds people;
- two flags at 10% reach two different tenths.

A guest has no id to hash, so a percentage never includes guests. A role that
`auth.guest_roles` grants to guests does count.

**A flag nobody configured is off.** Rules that do not parse (an unknown key, a
`percent` of 120, a role given as a string) are an error when `Features` is
first used, so a typo cannot quietly switch a feature off.

## Changing the answer

The `feature.active` filter has the last word. It receives the answer, the
flag's name and the `Identity`, for an override stored in a database or a
query-string switch for staff. A module that owns a flag can also set it in
code with `$features->define('name', $rule)`.

A route behind a flag answers 404 while the flag is off. The check runs after
the rate limit and before authentication, so a page that is switched off looks
like no page at all.
