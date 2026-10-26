# Vendored browser assets

Served from `public/assets/vendor/` (at `/assets/core/vendor/…`), never from a
CDN. LPHP's default Content-Security-Policy allows only same-origin scripts, and
a panel that controls servers should not depend on a third party being up and
honest. Update one only by replacing the file and this table in the same change.

| File | Version | Source | SHA-256 |
|---|---|---|---|
| `vendor/bootstrap/css/bootstrap.min.css` | Bootstrap 5.3.3 | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css` | `3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8` |
| `vendor/bootstrap/js/bootstrap.bundle.min.js` | Bootstrap 5.3.3 (includes Popper) | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js` | `0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c` |
| `vendor/jquery/jquery.min.js` | jQuery 3.7.1 | `https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js` | `fc9a93dd241f6b045cbff0481cf4e1901becd0e12fb45166a8f17f95823f0b1a` (matches jQuery's published SRI) |

The minified files reference source maps that are not shipped; a browser's
developer tools report them missing, which is harmless.
