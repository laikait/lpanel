# Localization

<!-- Twig below: GitHub Pages must not run it as Liquid. {% raw %} -->

Translations are PHP files. The application has its own in `lang/`, and every
module can ship its own in its `lang/` directory. Templates translate with the
`local` filter, and PHP code uses the `Localization` service.

## Translate in a template

```twig
{{ 'updated'|local }}
{{ 'user_update_success'|local({user: user.name}) }}
{{ 'Billing.invoice_created'|local }}
```

With `lang/en.php`:

```php
<?php

declare(strict_types=1);

return [
    'updated' => 'Updated',
    'user_update_success' => ':user updated successfully',
];
```

the second line prints `Some User updated successfully`.

The result is text, and Twig escapes it like every other value: a parameter
holding `<script>` prints as `&lt;script&gt;`, and so does HTML written into
the translation itself. There is no way to mark a translation as trusted HTML.

In a PHP template, `$view->local()` does the same and you escape it yourself:

```php
<?= $view->escaper()->html($view->local('user_update_success', ['user' => $name])) ?>
```

## Translate in PHP

Inject `Localization`:

```php
use App\Engine\Localization\Localization;

final class InvoiceMailer
{
    public function __construct(private readonly Localization $localization) {}

    public function subject(string $user): string
    {
        return $this->localization->get('Billing.invoice_for', ['user' => $user]);
    }
}
```

| Method | Does |
|---|---|
| `get($key, $parameters = [])` | the translation, or `$key` itself when there is none |
| `has($key)` | whether a translation exists (in the active locale or a fallback) |
| `locale()` | the active locale, decided on first use |
| `setLocale('bn')` | use `bn` from now on; throws `LocalizationException` if `lang/bn.php` is missing |
| `setLocale('admin/bn')` | use `bn`, reading `lang/admin/bn.php` first, then `lang/admin/en.php`, then the files of `lang/` itself |
| `directory()` | the sub-directory chosen that way (`admin`), or `null` |
| `available()` | every locale in `lang/` |

`setLocale()` is for a command, a job, an email in the recipient's language or
a test. It does not write a cookie.

A sub-directory keeps one part of an application's strings together, such as an
admin panel's in `lang/admin/`. `locale()` is still `bn`, so `<html lang>` and
plural rules are unchanged. Only code can choose a directory -- a cookie, a
header or the country map never can -- and it must be a plain name that exists
under `lang/` (`admin`, `admin/reports`); anything else throws
`LocalizationException`.

## Translation files

```
lang/
  en.php           the application's English, and the fallback for every language
  bn.php
  pt-BR.php
  countries.php    country → language policy (see below)
modules/Billing/lang/
  en.php
  bn.php
```

- A file returns an array of strings — or, for a message that depends on a
  count, of [plural forms](#plurals). Anything else stops the request with a
  `LocalizationException` naming the file.
- **The files are the list of languages.** `lang/de.php` existing is what makes
  `de` available; there is no list to keep in step with it.
- A file is named by its canonical tag: `en`, `bn`, `pt-BR`, `es-419`,
  `zh-Hant`. `pt_BR.php` or `EN.php` is not read.
- Only the active language's files are loaded, once per request, command or
  job. Edits show on the next request; there is nothing to clear.

## Plurals

A message that depends on a number gives one form per plural category, and the
`count` parameter picks one by the rules of the language:

```php
// lang/en.php
'cart' => [
    '=0'    => 'Your cart is empty',
    'one'   => ':count item in your cart',
    'other' => ':count items in your cart',
],
```

```twig
{{ 'cart'|local({count: cart.size}) }}
```

```php
$localization->get('cart', ['count' => 3]);   // "3 items in your cart"
```

- **Categories** are CLDR's: `zero`, `one`, `two`, `few`, `many`, `other`. Each
  language uses some of them — English `one` and `other`, Russian adds `few` and
  `many`, Arabic uses all six — and the rules are ICU's, through `intl`.
  Bengali counts 0 as `one`, for example; you never write that rule yourself.
- **`=0`, `=1`…** match one exact count, and are tried first.
- **`other` is required**, and is used when the count's category is missing or
  there is no `count` at all.
- A message found in the fallback language is pluralised by **that** language's
  rules, so an English fallback reads as correct English.

## Module translations

A module that has a `lang/` directory has translations. Nothing in `module.php`
declares them: the directory is the declaration, the same as `Templates/`.

A module's keys are prefixed with the same name its templates have:

| Module | Its key `invoice_created` is |
|---|---|
| `modules/Shared/lang/` | `shared.invoice_created` |
| `modules/Billing/lang/` | `Billing.invoice_created` |
| `modules/Stripe/lang/` | `Stripe.invoice_created` |

Every other key is looked up in the application's `lang/`. A module cannot
replace an application key, and two modules cannot collide, because no two
modules have the same name. Remove or disable a module and its keys stop
resolving; nothing else has to change.

## Which language is used

The first of these that names an available language wins:

1. **The `language` cookie.** The visitor chose.
2. **The visitor's country**, through `lang/countries.php`.
3. **The browser's `Accept-Language`**, in its order of preference.
4. **`en`.** If there is no `lang/en.php`, the first language that exists.

Each value is tried from most to least specific, so a cookie of `bn-BD` is
satisfied by `lang/bn.php`. A value with no file, or one that is not a language
tag at all (`../../config`), is skipped. Only the application's `lang/` decides
what is available.

The order is deliberate: a visitor in Bangladesh whose browser was installed in
English sees Bengali when `countries.php` says `'BD' => 'bn'`, until they choose
otherwise.

A command or a job has no request, so it runs in `en` unless it calls
`setLocale()`. A queue worker starts every job in `en` again.

## Missing translations

A key is looked up in the active language, then each less specific form of it
(`bn-BD`, then `bn`), then `en`. If all of them miss, the key itself is shown,
so a missing translation is visible on the page rather than blank. Modules
follow the same rule.

A placeholder with no parameter stays as it is: `:user updated successfully`.

With `APP_DEBUG=true`, each missing key is logged once per request at `debug`
level, with the key and the locale.

## Letting a visitor choose

Set the `language` cookie from your own route. Check the value first:

```php
use App\Engine\Http\Cookie;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;

$routes->post('/language', static function (Request $request, Localization $localization): RedirectResponse {
    $locale = (string) $request->input('locale');

    if (!\in_array($locale, $localization->available(), true)) {
        $locale = $localization->locale();
    }

    return (new RedirectResponse('/'))->withCookie(new Cookie(
        'language',
        $locale,
        expires: \time() + 31_536_000,
        secure: $request->isSecure(),
    ));
})->name('language.switch');
```

The cookie is `HttpOnly` and `SameSite=Lax` by default. The framework does not
render a language picker; that is your template's job.

## Country detection

Off by default. Behind a CDN or proxy that sends the visitor's country, name the
header and list the proxy as trusted:

```php
// config/localization.php
return ['country_header' => 'CF-IPCountry'];

// config/http.php
return ['trusted_proxies' => ['173.245.48.0/20']];   // an address or a CIDR block
```

or `LOCALIZATION_COUNTRY_HEADER=CF-IPCountry` in the environment.

**The header is read only when the request came from a trusted proxy** — the
same `http.trusted_proxies` list that decides whether `X-Forwarded-For` is
believed. From anyone else it is ignored, so a client cannot pick its own
country. `XX` and anything that is not a two-letter code count as unknown.

Then map countries to languages in `lang/countries.php`:

```php
<?php

declare(strict_types=1);

return [
    'BD' => 'bn',
    'US' => 'en',
    'DE' => 'de',
];
```

This is the application's policy for visitors from each country, not a claim
that a country has one language, and a language named here is used only if its
file exists.

### A local MaxMind database

Without a CDN, the country can come from a MaxMind database on the server:
GeoLite2 (free, needs a MaxMind account to download) or GeoIP2, **Country or
City** — both have the country, and City is simply larger. The reader,
`maxmind-db/reader`, is installed with the framework; only the database file is
yours to download.

```php
// config/localization.php
return ['maxmind_database' => 'system/GeoIP/GeoLite2-Country.mmdb'];
```

or `LOCALIZATION_MAXMIND_DATABASE=...`. A relative path is from the project
root. Keep the file outside `public/`, and refresh it with MaxMind's
`geoipupdate` — addresses move between countries.

- The lookup is local: a few microseconds, no network, and the file is opened
  only when a request actually needs a country.
- The address is `Request::ip()`, which believes `X-Forwarded-For` only from
  `http.trusted_proxies`. Behind an unlisted proxy every visitor looks like the
  proxy.
- The record's `country` is used, and `registered_country` only when there is
  none. An address MaxMind does not know (a private network, localhost) is no
  country, and resolution moves on to the browser.
- A missing file, a file that is not a MaxMind database, or one with no
  countries (ASN, ISP) stops the request with a `LocalizationException` that
  says which.

**MaxMind tells you where an address is, not what its user reads.** The
language still comes from `lang/countries.php`. A record's `names` field holds
the *country's name* in a few languages; it is not the visitor's language and
is not used.

With both `country_header` and `maxmind_database` set, the trusted header is
asked first and the database answers when it has nothing.

### Your own country source

To use something else, bind `CountryResolver` in a module:

```php
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Http\Request;
use App\Engine\Localization\CountryResolver;

$module->services(static function (ServiceRegistrar $services): void {
    $services->singleton(CountryResolver::class, MyCountryResolver::class);
});

final class MyCountryResolver implements CountryResolver
{
    public function country(Request $request): ?string
    {
        // A two-letter code, or null when unknown.
    }
}
```

Keep it local: it runs for any request that translates something and has no
language cookie.

## Caches in front of the application

A response that translated something using the request's language carries:

```
Vary: Cookie, Accept-Language
```

plus the country header when one is configured, so a CDN or page cache keeps
one copy per language instead of serving the first visitor's to everyone. A
response that translated nothing, or used `setLocale()`, is not marked.

A country found in a MaxMind database comes from the visitor's address, which
no `Vary` header can name. Do not put a shared page cache in front of pages
localized that way; use the CDN's country header instead, or mark those
responses `Cache-Control: private`.

## If it doesn't work

| Symptom | Cause | Fix |
|---|---|---|
| The key is printed instead of the text | No file in the chain has it, or the prefix is wrong | Check the key in `lang/en.php`; a module key starts with its folder name: `Billing.` |
| `Unknown "local" filter` | A Twig engine built outside `Bootstrap` | Render through the application's `TemplateManager` |
| The cookie is ignored | No `lang/<value>.php`, or the value is not a tag | Name the file exactly as the tag: `pt-BR.php` |
| The country is ignored | The request did not come from `http.trusted_proxies`, or no `country_header` | Configure both |
| `the MaxMind reader is not installed` | The package was removed from `vendor/` (it is a regular dependency) | `composer install` |
| Every visitor gets the same country from MaxMind | The app sees the proxy's address | Add the proxy to `http.trusted_proxies` |
| `pt_BR.php` is not a language | File names are canonical tags | Rename it `pt-BR.php` |

<!-- {% endraw %} -->
