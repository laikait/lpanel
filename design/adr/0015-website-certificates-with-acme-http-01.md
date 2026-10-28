# ADR-0015: Website certificates from Let's Encrypt (ACME HTTP-01), through the same checked change

- **Status:** Accepted (product owner, 2026-10-28: owner customers may get, renew and remove their own websites' certificates; every website gets one automatically, retried with growing gaps; once it has one, HTTP redirects to HTTPS by default, a setting the owner can switch off)
- **Context:** M7 adds HTTPS to the websites of [ADR-0014](0014-websites-through-the-hosting-nginx.md). Plan §3.3 and §4 Phase 3 call for ACME issuance and renewal with status and failure diagnostics, through the hosting backend. The panel's own certificate stays the administrator's ([ADR-0012](0012-panel-package-php-fpm-and-agent-port.md), Q1 (a)); nothing here touches the panel's Nginx.

## Decision

### Who does what

| Step | Where | Why |
|---|---|---|
| decide a certificate is due, record it, show it | the panel (`website_certificates`, `certificates:renew` every five minutes) | every change to a server stays a signed operation the panel asked for and audits |
| make the key and the request | `lpa-exec`, in memory | the key is made on the server and never leaves it; nothing secret reaches the panel or the database |
| talk to the authority | `lpa-exec`, with `golang.org/x/crypto/acme` | its directory URL is `lpa-exec`'s command line (`--acme-directory`, Let's Encrypt by default), never an operation's payload, so the panel cannot send it anywhere else |
| answer the checks | the hosting Nginx, from `/var/lib/lpa-acme/<website id>/` | see below |
| serve it | `website.certificate.issue`, through ADR-0014's staged `nginx -t`, swap, reload, probe and verified rollback | a certificate is one more input to the website's file, not a separate path |

The ACME account key lives in `/var/lib/lpa-exec/acme/` (`0600`, one account per directory URL), registered on first use without an e-mail address: Let's Encrypt no longer sends expiry mail, and the panel tracks expiry itself. Registering accepts the authority's subscriber agreement on the server owner's behalf.

`golang.org/x/crypto` is `lpa`'s first module outside the standard library (ADR-0001 allows one with a reason): it is the Go project's own, and writing RFC 8555 by hand is the riskier choice. Version v0.48.0, the last that builds with Go 1.24.

### A path customers cannot hijack

Every website file now has

```
location ^~ /.well-known/acme-challenge/ {
    alias /var/lib/lpa-acme/<website id>/;
    default_type text/plain;
}
```

The directory is root's (`0755`, made by the package; the token files `0644`, written only while an order is open and removed after). `^~` stops Nginx looking at the account's regex locations, and the redirect grammar refuses `/.well-known/acme-challenge/` (like `/.well-known/lpanel-probe`), so neither a file in the document root nor a redirect can answer for LPanel. The location stays in a suspended website's file, so its certificate can still be renewed.

### The website file with a certificate

The certificate file is `/etc/nginx/lpanel/tls/<website id>-<hash>.pem` (key, then chain), `0600` in a `0700` directory: only the Nginx master, as root, reads it. Its name changes with every certificate, so the new file is written before the change and nothing Nginx serves reads it until the reload; on success the previous file is deleted, on rollback the new one.

- `listen 443 ssl` (and `[::]:443`), TLS 1.2 and 1.3, a shared session cache. HTTP/2 as the installed Nginx spells it: `http2 on;` from 1.25.1, `listen … http2` before, none if `nginx -V` shows no `http_v2` module.
- **With `https_redirect`** (a website setting, on by default): port 80 answers only the probe and the checks and sends everything else `301` to `https://$host$request_uri`; port 443 serves the website.
- **Without it:** one server block answers both.
- Without a certificate, `https_redirect` changes nothing.

Before asking, `lpa-exec` checks that Nginx is serving the website's current file (a file from before this ADR is rewritten first, so it has the location) and that every domain has a DNS address: a domain without one can only fail, and failures count against the authority's rate limits. What comes back must be for the key it made, cover every domain, and be valid now, or it is not installed. A refusal or a failed check is `certificate_failed` with the authority's own words for which domain failed; nothing on the server changed.

### When LPanel asks

- **First certificate:** within five minutes of a website being served, then after failures 1 hour, 6 hours, and a day each time; after 9 failures in a row (about a week) LPanel stops asking on its own and says so. Someone clicking "Get certificate now" starts again from the beginning.
- **Renewal:** two thirds of the way through the certificate's life (day 60 of 90), retried the same way, and never given up while LPanel still serves the website. A suspended website is renewed too.
- **A new domain** the certificate does not cover: asked for at once.
- **A certificate file missing or changed** on the server, which `websites.check` reports (and which makes `nginx -t` fail for every website): asked for at once, audited as `website.certificate.drift`. The new certificate has a new file name, so the website's file stops naming the bad one.
- **Removed** (by an administrator or the owner): HTTP only, the key deleted, and LPanel stops asking until someone gets one again.
- A website being changed, or one whose file was edited by hand, waits; the agent would refuse the latter too.

A failed request is the certificate's story, not the website's: the website keeps its status and shows no error banner; the HTTPS card shows the authority's message and the next try.

### Network

`lpa-exec` must now reach the authority over HTTPS, so its unit no longer limits IP traffic to `localhost`. It still refuses link-local (cloud metadata services) and multicast addresses (`IPAddressDeny=link-local multicast`); the only address it connects to beyond localhost is the directory's, which only its command line names. `nginx -t` also binds port 443 now, under the `CAP_NET_BIND_SERVICE` it already had.

### Testing

The plan asks for Let's Encrypt's staging server in CI. Staging, like production, checks domains over the internet: it needs a public DNS name pointing at a reachable port 80, which a CI container does not have. The package tests use **Pebble** (Let's Encrypt's own test server, v2.10.1) inside the container instead, with the website names in `/etc/hosts`, so the whole path is real: order, HTTP-01 through the hosting Nginx, finalize, install, HTTPS served and verified against Pebble's root, renewal, rollback, removal. Going live against staging is a manual check on a server with a public name: `lpa exec --acme-directory https://acme-staging-v02.api.letsencrypt.org/directory` in a drop-in.

## Consequences

- Websites are served over HTTPS without anyone doing anything, once their domains point at the server.
- A server whose domains are not public yet tries for a week and then waits; the card says why.
- The panel never holds a private key. A compromised panel can make the server ask for certificates for the website's own domains, which it could already serve.
- `lpa-exec`'s sandbox is wider: it can open outbound connections. Its `systemd-analyze security` score is checked in the package test.
- Certificates are not revoked on removal; the key is deleted, and the certificate expires on its own.
- Purging `lpa` leaves the websites serving with their certificates, which are then no longer renewed.
