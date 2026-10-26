# Assets

An *asset* is a file the browser downloads alongside a page: a stylesheet, a
script, an image, a font. This page covers how to link to one, where the files
live, and how the framework stops that from becoming a way to download your
source code.

## Link to a file

`asset()` turns a file's logical name into a URL. Use it in a template:

<!-- {% raw %} -->

```twig
<link rel="stylesheet" href="{{ view.asset().core('css/app.css') }}">
<script src="{{ view.asset().module('Example', 'js/example.js') }}"></script>
```

<!-- {% endraw %} -->

or in PHP:

```php
asset()->core('js/app.js');                    // /assets/core/js/app.js?v=9c81f4a2
asset()->template('css/app.css');              // /assets/template/css/app.css?v=3f1c8d70
asset()->module('Example', 'js/example.js');   // /assets/module/Example/js/example.js?v=...
asset()->module('Stripe', 'js/stripe.js');
```

**Always link through `asset()`.** A hand-written `/assets/...` URL works, but
it carries no `?v=` — so when you change the file, browsers keep showing the old
one. The `?v=` is how the browser finds out.

## Where the files go

Each place has its own URL prefix:

| Write the file here | It is served at | Link to it with |
|---|---|---|
| `public/assets/` | `/assets/core/` | `asset()->core('css/app.css')` |
| `templates/assets/` | `/assets/template/` | `asset()->template('css/app.css')` |
| `templates/dark/assets/` | `/assets/template/dark/` | `asset()->template('dark', 'css/app.css')` |
| `templates/admin/dark/assets/` | `/assets/template/admin/dark/` | `asset()->template('admin/dark', 'css/app.css')` |
| `modules/Example/assets/` | `/assets/module/Example/` | `asset()->module('Example', 'js/x.js')` |
| `modules/Stripe/assets/` | `/assets/module/Stripe/` | `asset()->module('Stripe', 'js/x.js')` |

Use `public/assets/` for the application's own files and `templates/assets/` for
files belonging to the look of the site. A module's files go in its own
`assets/` folder, so that installing the module brings them with it.

**A theme is published because it is registered**, in `templates.themes` or by a
provider; a folder under `templates/` alone publishes nothing. See
[Themes](templates.md#themes). A theme's assets are always named in the call and
never fall back: a file missing from `templates/admin/dark/assets/` is a 404, not
the copy in `templates/assets/`. When a URL could name either, the longest
registered theme wins, so `/assets/template/admin/dark/…` is the `admin/dark`
theme even when `admin` is registered too.

**A module is published because it has an `assets/` folder** — there is nothing
to declare in `module.php`. Create the folder, put a file in it, and it is
reachable. `php laika asset:list` prints everything that is published.

The shared module is the one exception: it has no name of its own, so no URL
could address it. Files that belong to the application as a whole go in
`public/assets/`.

## What may be served

A file is delivered only if it passes all four of these, in order:

1. **The name is plain.** Every part of the path must match
   `[A-Za-z0-9_][A-Za-z0-9._-]*`. There is no way to write `..`, a backslash, a
   drive letter, or a leading dot, so `.env`, `.htaccess` and `.git` are out
   without anyone having to list them.
2. **The extension is on the allow list**, which is the `MimeTypes` class.
   There is no fallback type, so `.php`, `.phtml`, `.env`, `.ini`, `.sh` and
   anything else nobody listed are simply not assets. `app.js.php` is judged by
   its last extension, like every other file.
3. **The file exists.**
4. **The file is really inside the published folder**, checked with
   `realpath()` on both sides. This is the symlink check: a link inside
   `assets/` pointing at `/etc/passwd` passes steps 1 to 3 and fails here.

Every refusal is a **404**, including the ones that were really "you tried to
escape the folder". Telling the difference would tell whoever is probing which
attempt got closer. With debug mode on, the reason is in the body, because the
person reading it then is you. No refusal ever prints an absolute path.

**HTML is never served as an asset.** Serving HTML that someone uploaded, from
your own domain, is a cross-site scripting hole. Content that needs to be a page
belongs behind a route. SVG *is* served, because pages need it, but with a
`Content-Security-Policy` of `default-src 'none'` and `sandbox`, so opening one
in a tab cannot run anything. Everything gets `X-Content-Type-Options: nosniff`,
and the type comes from the extension rather than from looking inside the file.

## Versions and caching

The `?v=9c81f4a2` on a URL is a hash of the file's contents. Change the file and
the hash changes, so the browser sees a new URL and fetches it; leave the file
alone and the browser keeps its copy.

| A URL that | Gets |
|---|---|
| has a `?v=` | `Cache-Control: public, max-age=31536000, immutable` |
| has none | `Cache-Control: public, max-age=0, must-revalidate`, plus an ETag |

"Immutable" is a promise about that URL, not about the file. `If-None-Match` and
`If-Modified-Since` both produce a 304.

**Why a content hash rather than the file's date?** Because deploy tools keep
timestamps — `rsync -a`, `tar -p`, a checkout of unchanged files — so a changed
file can arrive with an unchanged date, and every browser keeps the old copy. A
hash of the contents cannot be wrong about whether the contents changed. Set
`assets.versioning` to `modified` or `none` if you would rather not pay for the
read.

Outside debug mode the hash is computed once and kept in the cache, keyed by the
file's size and modification time as well as its name. A deploy that changes a
file therefore gets a new `?v=` even if nobody runs `cache:clear`; the one case
it misses is a file rewritten with the same size *and* the same timestamp, which
`cache:clear` after the deploy covers.

**If you use a bundler**, its manifest wins. `assets/manifest.json` maps the name
you write to the file it built, in either of these shapes:

```json
{ "js/app.js": "js/app.9c81f4a2.js",
  "css/app.css": { "path": "css/app.3f1c.css" } }
```

Your code still writes `asset()->core('js/app.js')`. A URL that came from a
manifest carries no `?v=`, because the filename already carries the hash.

**Byte ranges are not supported.** Responses say `Accept-Ranges: none` rather
than quietly returning the whole file. Serving video is a job for the web server
or a CDN.

## Settings

| Key | Default | What it does |
|---|---|---|
| `assets.url` | `null` | The prefix put in front of every asset URL. `null` means "wherever the application is installed", which is right both in a subfolder and under the built-in server. Point it at a CDN to move delivery off this server entirely. |
| `assets.versioning` | `content` | `content`, `modified` or `none`. See [Versions and caching](#versions-and-caching). |
| `assets.manifests` | `true` | Whether a `manifest.json` in a published folder is read. |
| `assets.max_age` | `31536000` | The seconds in the immutable `Cache-Control`, for versioned URLs only. |

One more thing decides behaviour and is not in that table: **debug mode**. With
it on, a missing asset throws immediately, naming the rule that refused it,
because a typo should be loud while you are writing it. With it off, the URL is
returned without a version, because a stale stylesheet is a smaller problem than
a page that will not render — and the 404 shows up in the access log anyway.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A module's stylesheet is a 404 | The file is not in the module's `assets/` folder, or its extension is not on the list | `php laika asset:list` shows what is published |
| The browser keeps the old stylesheet | The URL was written by hand, so it has no `?v=` | Link through `asset()` |
| An `.html` file in `assets/` is a 404 | HTML is never served as an asset | Serve it from a route |
| A missing asset throws in development but not in production | That is deliberate; see the note above | Fix the path the message names |
| A permission check on an asset never runs | Module code takes no part in serving assets | Serve the file from a route with `meta(['can' => ...])` |

## Why it works this way

### Why PHP serves them at all

The web server serves only `public/`, and `modules/` sits outside it — next to
`module.php` and every repository. So a module's `assets/` folder is unreachable
by the web server *on purpose*, and the asset server is what makes those files
reachable without opening up the folder that holds the source.

With a module called `Example` installed that ships `assets/js/example.js`, you
can see both halves at once:

```bash
curl -i http://localhost/framework/modules/Example/assets/js/example.js  # 404, from the application
curl -i http://localhost/framework/assets/module/Example/js/example.js           # 200
```

`public/assets/` is different: the web server can serve it directly, and letting
it is faster and fully supported. The URL is the same either way.

### Building a URL and delivering a file are separate jobs

`AssetManager` builds URLs and never delivers a file; it cannot see `Request` or
`Response` at all, and an architecture test keeps it that way. `AssetServer`
delivers. They share one constant, the `/assets` prefix.

They are separate because they happen at different times. A URL is built in a
template, a console command or a queued email, where there may be no HTTP
request anywhere. Delivery is one HTTP handler that a web server or a CDN should
eventually take over — which is what pointing `assets.url` at a CDN does, with
no change to application code.

### An asset request loads no module

The specification puts it bluntly: *"`/assets/...` should not initialize
billing."* It does not. A request under `/assets/` is answered after **discovery
alone**: no `module.php` runs, no service is created, no `onBoot` fires, no route
is registered.

That is possible precisely because publishing was never a declaration. Having an
`assets/` folder is the whole of it, and discovery already knows which modules
have one.

You can watch it on a real server. Install a module with a route and an asset,
make its `module.php` throw, and its pages fail while its assets do not:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/framework/customers.json                       # 500
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/framework/assets/module/Example/js/example.js  # 200
```

The decision is made on the raw path, before any filter runs, because the filters
that could change it belong to modules that have not loaded. The engine's own
listeners — the request size limit, the security headers — are attached by the
bootstrap, so they still apply. `app.booted` and every module listener do not.

**The consequence: module code cannot take part in delivering an asset.** A
module that declares an `asset.response` listener is refused at boot, by name.
The reason is that in production the recommended setup has the web server or a
CDN deliver assets without PHP at all, so such a listener was only ever
guaranteed to run in a test. A file that needs a permission check is not a public
asset: serve it from a route, where `meta(['can' => ...])` already applies.
`asset.response` remains for engine listeners attached during bootstrap.

### One more thing

Asset URLs are built once, when the application is built, from the execution
context; route URLs are worked out per request. Under every SAPI this framework
targets those come from the same `$_SERVER`, so they always agree. A resident
worker serving many requests in one process is the case where they could not,
and that is a reason to revisit this rather than a bug today.
