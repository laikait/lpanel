# LPHP Localization — Implementation Plan (rev 2)

Status: planned for `laikait/lphp`. Revised against the v2.1.2 source.

## 1. Goals

- PHP translation files (`lang/en.php`), root + module-owned
- Twig `|local` filter, PHP-template `$view->local()`, `local()` helper
- Named `:param` replacement
- Locale priority: **cookie → country → country map → Accept-Language → en**
- `language` cookie for explicit choice
- Per-request in-memory cache, lazy resolution
- Works in HTTP, CLI, queue workers, tests
- No DB, no Relay, no external service, no new Composer dependency

## 2. Hard constraints (from the codebase)

| Rule | Source |
|---|---|
| Only `TwigTemplateEngine.php` may reference `Twig\` inside `engine/Template/`, and no other engine code should either | `tests/Architecture/ArchitectureTest.php::test_twig_is_confined_to_its_own_engine` |
| Module resources are declared by having the directory, not by a method call | `ModuleDiscovery` → `ModuleDefinition::hasAssets/hasTemplates` |
| Module namespaces: `shared`, `plugin.<Dir>`, `gateway.<Dir>` | `ModuleManager::publishTemplates()` |
| Trusted-proxy list already exists | `Request::$trustedProxies`, `Application::trustedProxies()` |
| Cookies: `Response::withCookie(new Cookie(...))`, defaults HttpOnly + SameSite=Lax | `engine/Http/Cookie.php` |
| Helpers are an existing convention | `engine/Support/helpers.php` (`asset()`, `template()`, …) |
| Workers are long-running; per-job state must be reset | `JobRunner` fires `job.started` |
| Docs index is checked | `tests/Architecture/DocumentationTest.php` |

## 3. Files

```text
engine/Localization/
├── Localization.php        public API (locale, setLocale, has, get, reset)
├── LocaleResolver.php      cookie → country → map → Accept-Language → en
├── Locale.php              normalize/validate tags, candidates (en-US → en)
├── AcceptLanguage.php      header parser (bounded, q-values)
├── TranslationLoader.php   require + validate array, in-memory cache
├── TranslationCatalog.php  namespace → lang dir, available locales (built at boot)
├── CountryResolver.php     interface: country(): ?string
├── HeaderCountryResolver.php   trusted-proxy header (e.g. CF-IPCountry)
├── NullCountryResolver.php     default
└── LocalizationException.php

engine/Template/TwigTemplateEngine.php   registers `local` filter (only Twig touchpoint)
engine/Template/TemplateView.php         + local(string $key, array $params = []): string
engine/Module/ModuleDefinition.php       + const LANG = 'lang'; + bool $hasLang
engine/Module/ModuleDiscovery.php        + hasLang: is_dir(<module>/lang)
engine/Module/ModuleManager.php          + publishTranslations() (mirrors publishTemplates)
engine/Http/Request.php                  fromTrustedProxy() → public
engine/Support/helpers.php               + local()
engine/Bootstrap/Bootstrap.php           wire Localization singleton, pass to Twig engine

lang/en.php, lang/bn.php, lang/countries.php
docs/reference/localization.md  (+ link in docs/README.md)
CHANGELOG.md
```

## 4. Translation files

```text
lang/en.php                  root, unqualified keys
lang/bn.php
lang/countries.php           country → preferred language (policy, not a registry)
modules/Shared/lang/en.php               → shared.<key>
modules/Billing/lang/en.php      → Billing.<key>
modules/Stripe/lang/en.php      → Stripe.<key>
```

- Each file returns a flat `array<string,string>`. Non-array → `LocalizationException` naming the file.
- Available locales = files that exist. No `supported_languages` config.
- `countries.php` is excluded from locale discovery.

## 5. Module translations

- **No `$module->translations()` method.** A `lang/` directory in the module is the declaration, same as `Templates/` and `assets/`.
- `ModuleDiscovery` records `hasLang`; it is cached with the rest of `ModuleDefinition`, so no scan per request.
- `ModuleManager::publishTranslations()` registers `namespace → dir` in `TranslationCatalog`, using the **same namespace as templates** (`shared`, `plugin.Billing`, `Stripe`).
- Namespaces are unique by construction (kind + directory), so no collision detection is needed.
- Disabled/removed module → not published → its keys resolve to the key itself.

Key resolution: longest registered namespace prefix wins; otherwise the key is a root key.

```text
Billing.invoice_created → ns plugin.Billing, key invoice_created
updated                         → root, key updated
```

## 6. Locale format

- Tag: `^[a-z]{2,3}(?:-(?:[A-Z][a-z]{3}))?(?:-(?:[A-Z]{2}|[0-9]{3}))?$`
  (supports `en`, `en-US`, `es-419`, `zh-Hant`, `zh-Hant-TW`)
- Normalize: `EN-us` → `en-US`, `zh-hant-tw` → `zh-Hant-TW`, `_` → `-`.
- Candidates, most specific first: `zh-Hant-TW → zh-Hant → zh`, `en-US → en`.
- Invalid values are dropped. The filename is only ever built from a validated tag **and** checked against the catalog's known list — never `require $dir . $input`.

## 7. Locale resolution

Order is fixed:

```text
1. cookie `language`      valid + candidate in root locales → use
2. CountryResolver        → ISO alpha-2 (uppercased, validated) or null
3. lang/countries.php     country → tag; candidate in root locales → use
4. Accept-Language        q-sorted; each tag's candidates in root locales → use
5. en                     if lang/en.php exists; else first available; else 'en'
```

- Availability is checked against **root** `lang/` only (the app's language set).
- Resolved **lazily** on first `locale()`/`get()` call, then memoized. No middleware.
- CLI / no Request in container: steps 1–4 skipped → `en` unless `setLocale()`.

### Accept-Language parser

- Truncate header to 1 KB, max 20 entries.
- Default `q=1`; invalid q → entry dropped; `q=0` → excluded.
- Stable sort by q desc; dedupe; ignore `*` and invalid tags.
- Never throws, never warns.

## 8. Country detection

```php
interface CountryResolver { public function country(): ?string; }
```

- Default binding: `NullCountryResolver`.
- `HeaderCountryResolver(Request, header: 'CF-IPCountry')` reads the header **only if `Request::fromTrustedProxy()`** (made public). Reuses existing trusted-proxy config — no new proxy settings.
- Enabled by env/config `localization.country_header` (e.g. `CF-IPCountry`); empty = off.
- Apps override by rebinding `CountryResolver` in their module's `services()` (MMDB, nginx, custom).
- `XX`, `T1` and non-alpha-2 values → null.

## 9. Localization service API

```php
$localization->locale(): string
$localization->setLocale(string $tag): void   // validates; throws on unavailable tag
$localization->has(string $key): bool
$localization->get(string $key, array $params = []): string
$localization->available(): list<string>
$localization->reset(): void                  // forget resolved locale + loaded arrays
```

- Registered as a **singleton** in `Bootstrap` (engine infrastructure, not `modules/Shared`).
- `setLocale()` does not write a cookie (HTTP concern).
- Queue: `Bootstrap` hooks `job.started` → `Localization::reset()` so locale never leaks between jobs.
- No static state.

## 10. Lookup & fallback

Same rule for root and modules (consistent):

```text
active locale → its less-specific candidates (bn-BD → bn) → en → key itself
```

- Mixing English into a Bengali page is preferred over showing raw keys; this is one rule for everything.
- Missing key in debug mode → `logger->debug('Missing translation', ['key' => ..., 'locale' => ...])` via existing Logging; no paths in output.

## 11. Parameters

- `:name` replaced via `strtr()` with keys sorted longest first (`:username` before `:user`).
- Scalars / `Stringable` only; others ignored.
- Missing param → placeholder stays visible.
- No ICU/plurals in this phase.

## 12. Templates

**Twig** — inside `TwigTemplateEngine::twig()` only:

```php
$twig->addFilter(new \Twig\TwigFilter('local', $this->localizer));
```

- The engine receives a plain `?\Closure(string, array): string` from `Bootstrap` (no Localization type in `engine/Template/`, keeps the engine standalone).
- Registered on every build, so compiled-cache templates still work.
- **Not** `is_safe` → Twig autoescaping stays on; HTML in translations is escaped.

```twig
{{ 'updated'|local }}
{{ 'user_update_success'|local({user: user.name}) }}
{{ 'Billing.invoice_created'|local }}
```

**PHP templates** — `TemplateView::local()` returns plain text; the author escapes as usual:

```php
<?= $view->escaper()->html($view->local('updated')) ?>
```

**Helper** — `local(string $key, array $params = []): string` in `helpers.php`, consistent with `asset()`/`template()`.

## 13. HTTP concerns

**Language switch** (application route, not framework UI):

```php
return (new RedirectResponse($back))->withCookie(
    new Cookie('language', $tag, expires: time() + 31536000, secure: $request->isSecure()),
);
```

Validate `$tag` against `$localization->available()` first.

**Caching — `Vary`**: `Bootstrap` attaches to the existing `response.instance` filter (`HttpKernel.php:111`) and, only if `Localization` resolved a locale from request data during this request, adds:

```text
Vary: Cookie, Accept-Language[, <country header>]
```

Without it a CDN/page cache serves one language to everyone.

## 14. Not in scope

Relay, DB translations, admin UI, machine translation, gettext, ICU, URL prefixes, session storage, mandatory GeoIP/MaxMind, HTML-safe translations, `lang/{locale}/*.php`, `__()`/`trans()`, static API, per-lookup filesystem scans, compiled translation cache.

## 15. Tests (existing PHPUnit layout)

**Unit**
- `Locale`: normalize, validate, candidates, traversal strings (`../../config`, `en/../../`, `<script>`, null bytes) rejected.
- `AcceptLanguage`: q-sort, default q, invalid q, `q=0`, `*`, duplicates, 10 KB header, garbage.
- `LocaleResolver` (fake `CountryResolver`): each priority level + cookie>country, country>browser, browser>en, mapped-but-missing file, unknown country, no Request.
- `TranslationLoader`: valid, empty, UTF-8, missing, non-array, loaded once.
- `Localization`: root/module/missing keys, `bn-BD → bn → en → key`, params (multiple, missing, empty, numeric, UTF-8, prefix overlap), `reset()`.
- `HeaderCountryResolver`: ignored without trusted proxy, honoured with it, invalid codes → null.

**Feature**
- Render real Twig via `TemplateManager`: `|local`, params, `<script>` param is escaped, module key.
- Twig with compile cache enabled still renders `|local`.
- Fixture module with `lang/` resolves; disabled fixture → key returned.
- `php laika` command context → `en`; `setLocale('bn')` works.
- Queue: locale set in job A not visible in job B.
- HTTP: `Vary` header present when localized.

**Architecture**
- Existing Twig-confinement test still passes.
- No `Relay` references.

## 16. Phases

1. `Locale`, `AcceptLanguage`, `TranslationLoader` + unit tests
2. `TranslationCatalog`, `ModuleDefinition::hasLang`, `publishTranslations()`
3. `Localization`, `LocaleResolver`, `CountryResolver` (+ Null/Header), `Request::fromTrustedProxy()` public
4. `Bootstrap` wiring, `job.started` reset, `Vary`
5. Twig filter, `TemplateView::local()`, `local()` helper
6. `lang/en.php`, `lang/bn.php`, `lang/countries.php`
7. Docs (`docs/reference/localization.md`, index link), CHANGELOG
8. `composer check`, `php laika module:list`, `php laika route:list`

## 17. Acceptance

- `{{ 'updated'|local }}` → active-locale text
- `{{ 'user_update_success'|local({user: 'Some User'}) }}` → `Some User updated successfully`
- `{{ 'Billing.invoice_created'|local }}` resolves from the module; gone when module removed
- `language=bn` → bn; country `BD` → bn when no cookie; browser only after both fail; `en` last
- Invalid locale input never reaches the filesystem
- One file load per locale per request/job
- Works in CLI and workers without a Request
- Output stays escaped
- `composer check` green, Twig-confinement test green
