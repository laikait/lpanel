# Deployment and security

This page is about putting the application on a web server safely:

1. why the web server must serve only `public/`,
2. setting it up with Apache or with nginx,
3. the checks to run afterwards, which prove no source code can be downloaded,
4. what to change when the site runs on more than one machine,
5. the two commands that make production fast.

Day-to-day operation — what to run, what to set, what to do when something is
stuck — is in [Running in production](running.md).

## The one rule: serve `public/` only

`public/` holds three things: `index.php`, its `.htaccess`, and the
application's own `assets/`. Everything else — `engine/`, `modules/`, `config/`,
`vendor/`, `.env`, `system/` — is one level **above** it.

That is the whole protection. A file the web server cannot see cannot be
downloaded, so there is no list of folders to deny and nothing to forget to add
to such a list. If `engine/` were reachable, anyone could read your source; if
`.env` were, they could read your database password.

Two tests keep it that way: an architecture test allows only those three entries
in `public/`, and `php laika security:check` fails if a second PHP file appears
there.

## Apache

Point the document root at `public/`:

```apache
<VirtualHost *:80>
    ServerName app.example.com
    DocumentRoot /srv/app/public
    <Directory /srv/app/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**`AllowOverride All` matters.** It lets `public/.htaccess` send every URL to
`index.php`. With `AllowOverride None` the home page works and every other page
is a 404, which is a confusing way to find out.

If routes give 404s, check that rewriting is on and overrides are allowed:

```bash
grep -E 'rewrite_module|AllowOverride' /path/to/httpd.conf
```

### Three settings only the server configuration can make

The application cannot change these, not even from `.htaccess`, and each one
is a gift to whoever is scanning:

```apache
ServerTokens Prod     # "Server: Apache", not the exact Apache, OpenSSL and PHP versions
ServerSignature Off   # no version footer on Apache's own error pages
TraceEnable Off       # TRACE echoes the request back, cookies and Authorization included
```

XAMPP ships `ServerTokens Full` and `ServerSignature On` in
`apache/conf/extra/httpd-default.conf`, and `TraceEnable` is on unless it is
turned off; Apache answers TRACE itself, before any `.htaccess` is read. Check:

```bash
curl -sI http://<host>/ | grep -i '^server:'                      # Apache, nothing more
curl -s -o /dev/null -w '%{http_code}\n' -X TRACE http://<host>/   # 405
```

`public/.htaccess` adds `X-Content-Type-Options: nosniff` to the files Apache
serves directly (they never reach PHP, so they would otherwise go without it);
that needs `mod_headers`, which is on in almost every build.

### When you cannot change the document root

On XAMPP (`http://localhost/framework/`) or on shared hosting, the web server
serves the project folder itself. The project's own `.htaccess` then forwards
**every** request into `public/`. So `/composer.json` becomes
`public/composer.json`, which does not exist, and the application answers with
its 404 page. Visitors never see `/public` in a URL.

If `mod_rewrite` is missing, that file refuses everything instead of serving
source code.

## nginx

Generate the server block instead of writing one:

```bash
php laika nginx:make --server-name=app.example.com --root=/srv/app --php=unix:/run/php/php8.3-fpm.sock
sudo cp nginx.conf /etc/nginx/conf.d/app.conf
sudo nginx -t && sudo systemctl reload nginx
```

| Option | Means | Default |
|---|---|---|
| `--server-name` | the host names nginx answers for | `_`, meaning any host |
| `--root` | the application folder (**not** its `public/`) | the folder you run the command in |
| `--php` | where PHP-FPM listens | `unix:/run/php/php-fpm.sock` |
| `--listen` | the port, or `address:port` | `80` |
| `--force` | replace an existing `nginx.conf` | off |

The file is written to the application folder, outside `public/`, and an
existing one is never replaced without `--force`, because you may have edited it.

The generated block serves `public/`, refuses dotfiles, runs no PHP file except
`index.php`, and serves `/assets/core/` straight from disk, with dotfiles
refused and `nosniff` added there too. nginx answers TRACE with 405 by itself.
Add `server_tokens off;` to the `http` block, so `Server:` does not carry the
nginx version. `nginx -t` only checks the syntax, so run the checks below as
well.

## Check it, after every deployment

These prove that source files cannot be downloaded. Each must be answered by the
**application** — its 404 page, or one of your routes — and never with the file's
contents or a redirect:

```bash
curl -i http://localhost/framework/composer.json
curl -i http://localhost/framework/engine/Core/Application.php
curl -i http://localhost/framework/modules/Shared/module.php
curl -i http://localhost/framework/vendor/autoload.php
curl -i http://localhost/framework/engine
curl -i http://localhost/framework/templates
```

Files starting with a dot are refused with **403**. `/.well-known/` is the one
exception, so that certificate authorities can check their challenges:

```bash
curl -i http://localhost/framework/.htaccess
```

A route of yours may use any of those paths — `/templates`, `/config/app` —
because none of them is a file the web server could serve.

These check that the asset layer has not become another way in. The first two
must be refused (**403** or **404**), the third must be **200**:

```bash
curl -i --path-as-is http://localhost/framework/assets/core/../composer.json
curl -i --path-as-is http://localhost/framework/assets/core/../index.php
curl -i http://localhost/framework/assets/core/css/app.css
```

With a module installed, try the same against it:
`/assets/module/<Name>/module.php` and `/assets/module/<Name>/../module.php` must
both be 404.

> Replace `http://localhost/framework` with your own address. `curl -i` prints
> the status line and headers, which is what you are reading.

## More than one web server

Three things are kept per machine by default, and are wrong as soon as a second
machine serves the same site — for the same reason each time: a file on one
machine is not a file on the other.

| Setting | Change | Otherwise |
|---|---|---|
| `session.store` | `file` → `database` | a visitor sent to the other machine is logged out |
| `security.counters` | `file`; no shared store exists yet | a limit of 60 becomes 60 per machine |
| `cache.store` | `file` → `database` | each machine caches, and clears, on its own |

**Sticky sessions do not fix the first one.** They just send each visitor back to
the same machine, and every session on a machine is lost when it restarts.

With `session.store`, `cache.store` or `queue.store` set to `database`,
`php laika migrate` creates the tables those stores need.

Two console switches are per machine too, because each writes a file under
`system/Runtime/`: `php laika down` and `php laika openapi:docs` / `openapi:auth`.
Run them on every web server, or set the API docs in `config/openapi.php`
instead, which every machine reads.

The scheduler is the opposite case. Its lock is a file on one machine, so
**`schedule:run` must run on exactly one host**. On three hosts, every scheduled
task runs three times.

The full list, including the queue and the log, is in
[More than one host](running.md#more-than-one-host).

## The production boot path

```bash
composer install --no-dev --optimize-autoloader
php laika cache:clear
php laika cache:warm
```

`cache:warm` writes two files:

- `system/Cache/config.php`: the whole resolved configuration;
- `system/Cache/modules.php`: what module discovery found — where each module is,
  and whether it has `assets/` and `Templates/` folders.

A request that finds both reads one prepared array for each, and searches no
folders. PHP's opcache keeps them in memory.

**There is no setting to switch this on.** The file existing is the switch. Only
`cache:warm` writes these files, which is why a cache can never be built
half-finished by an ordinary request.

**A debug process never reads the module cache.** That way, a developer who
warmed the cache once cannot lose an afternoon to a module that "does not
exist". `cache:warm` refuses to run when debug is on, so a debug configuration
is never frozen into a deployment.

### What the caches notice, and what they do not

| Change | Noticed? |
|---|---|
| An environment variable the configuration read | Yes: the configuration cache ignores itself. |
| `modules.paths` changed, or the application moved | Yes: the module cache ignores itself. |
| A module added or removed, a `config/` file edited | **No.** |

The last one is not an oversight: noticing it means checking the very folders the
cache exists to avoid, and file timestamps are unreliable on Windows and network
drives. That is why `cache:clear` belongs in your deployment script.

To see which path a process took:

```bash
php laika about
```

```
Boot path    config cached, modules cached
Boot path    config read from config/, modules scanned (debug never reads the cache)
```

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| The home page works, every other page is 404 | Apache rewriting is off, or `AllowOverride None`. | Enable `mod_rewrite` and set `AllowOverride All`. |
| A `curl` check returns file contents | The document root is the project folder and `.htaccess` is not being read. | Point the root at `public/`, or enable `AllowOverride All`. |
| `403` on every page | The PHP user cannot read the files, or `Require all granted` is missing. | Check the directory block and file ownership. |
| A config change does nothing | The configuration cache is still the old one. | `php laika cache:clear`, then `cache:warm`. |
| `cache:warm` refuses to run | Debug mode is on. | Set `APP_DEBUG=false` for the deployment. |
| Everything is 500 right after deploying | A boot error, hidden because debug is off. | On the host, run `APP_DEBUG=1 php laika about`. Never turn debug on for the web server. |

More in [Troubleshooting](../troubleshooting.md).
