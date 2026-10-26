<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\Cookie;
use App\Engine\Http\Request;

/**
 * Proof that an unsafe request came from this application's own pages.
 *
 * CSRF exists because a browser attaches cookies to a request whether or not
 * the page that made it belongs to you. A form on any site in the world can
 * POST to /transfer, and the session cookie goes with it. Nothing about
 * authentication catches that -- the user really is logged in, and really did
 * click -- so the defence has to be evidence that only your own page could have
 * supplied.
 *
 * **Two independent checks, and either one is enough to fail.**
 *
 * The first is the token: a value in a cookie that must be echoed back in a
 * form field or a header. Same-origin policy stops another site from reading
 * the cookie, so it cannot echo it. The token is signed with APP_KEY as well,
 * which proves this application issued it -- but not to whom: anybody can
 * fetch a page and be issued a valid one.
 *
 * **What stops a planted cookie is its name.** A sibling subdomain, or anyone
 * able to inject a Set-Cookie over plain HTTP, could otherwise set a token they
 * were issued themselves in the victim's browser and post the matching field.
 * Over HTTPS the cookie is called __Host-XSRF-TOKEN, and a browser accepts a
 * __Host- cookie only from a secure response of this exact host, with Path=/
 * and no Domain -- which neither of them can send. Only that name is read over
 * HTTPS, so an XSRF-TOKEN planted beside it counts for nothing. Plain HTTP,
 * where no prefix is possible, keeps the old name.
 *
 * The second is the Origin header, which browsers send on every unsafe
 * cross-origin request and which script cannot forge. It costs nothing, needs
 * no state, and catches the case where the token machinery has been wired up
 * wrongly. It is checked only when present, because a same-origin GET-turned-
 * POST from an old client may not send one.
 *
 * **Safe by default, opt out per route.** Every POST, PUT, PATCH and DELETE is
 * checked unless its route says `meta(['csrf' => false])`. The other way round
 * is one forgotten annotation away from an unprotected endpoint, and the
 * annotation is forgotten on the route somebody added in a hurry -- which is
 * reliably the one that matters.
 *
 * **What this cannot do.** It does not survive XSS: script running on your own
 * page can read the cookie like your own page can. It is not a substitute for
 * SameSite cookies, it is the layer underneath them.
 *
 * **A token is bound to a browser, not to a login.** The session layer closes
 * half of that gap by rotating the token whenever the session id changes -- see
 * rotate() -- so a token cannot outlive the identity it was issued under.
 * Binding a token to a particular session, so that one user's token cannot be
 * presented by another, needs an identity to bind to and belongs with
 * authentication rather than with storage.
 */
final class Csrf
{
    /**
     * Readable by script, on purpose.
     *
     * The name is the de-facto standard one, so an XMLHttpRequest wrapper or an
     * axios install already knows to echo it. HttpOnly is deliberately off:
     * this cookie is not a credential, it is a value that has to be readable to
     * be echoed, and the credential it protects stays HttpOnly.
     */
    public const COOKIE = 'XSRF-TOKEN';

    /**
     * The same cookie over HTTPS, which no other host can set. See the class
     * description. A script reading the cookie reads this name on HTTPS; see
     * cookieName(). security.csrf.host_prefix = false keeps COOKIE everywhere.
     */
    public const SECURE_COOKIE = '__Host-' . self::COOKIE;

    /** What a form posts. */
    public const FIELD = '_token';

    /** What a script sends, either spelling. */
    public const HEADERS = ['X-CSRF-TOKEN', 'X-XSRF-TOKEN'];

    /** Methods that must not change anything, and so need no proof. */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /** What the signature is bound to, so a CSRF token is not a signed URL. */
    public const CONTEXT = 'csrf';

    /**
     * The token issued during the current request, when the browser's own
     * could not be reused.
     *
     * Remembered because more than one thing asks for the token in a request:
     * the handler rendering a form, and the guard setting the cookie on the way
     * out. On a first visit there is no cookie to reuse, and two calls that
     * each signed a fresh token would put one in the form and another in the
     * cookie -- refusing every new visitor's first submission as a mismatch.
     *
     * Forgotten by begin() at the start of every request: a token issued to
     * one browser must never be handed to the next.
     */
    private ?string $issued = null;

    public function __construct(
        private readonly Signer $signer,
        private readonly bool $checkOrigin = true,
        private readonly int $lifetime = 7200,
        private readonly bool $hostPrefix = true,
    ) {}

    /** The cookie's name for this request: SECURE_COOKIE over HTTPS, COOKIE otherwise. */
    public function cookieName(Request $request): string
    {
        return $this->nameFor($request->isSecure());
    }

    /** A new request: nothing has been issued to it yet. */
    public function begin(): void
    {
        $this->issued = null;
    }

    public function isSigned(): bool
    {
        return $this->signer->isConfigured();
    }

    public function protects(string $method): bool
    {
        return !\in_array(\strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * The token this request should put in its forms.
     *
     * An existing valid cookie is reused rather than replaced. Rotating on
     * every response breaks the ordinary case of two tabs open on the same
     * site: the second tab's form would carry a token the cookie no longer
     * holds, and the user is shown a security error for using a browser
     * normally.
     *
     * Otherwise a new one is issued, once per request: every later call in the
     * same request, and the cookie, get that same token.
     */
    public function token(Request $request): string
    {
        if ($this->issued !== null) {
            return $this->issued;
        }

        $existing = $request->cookie($this->cookieName($request));

        if ($existing !== null && $this->signer->verify($existing, self::CONTEXT) !== null) {
            return $existing;
        }

        return $this->issued = $this->signer->sign(Signer::token(), self::CONTEXT);
    }

    /**
     * A new token, whatever the browser is currently holding.
     *
     * Called when the session id changes. A token issued to the anonymous page
     * that showed the login form must not stay valid against the session the
     * login created -- that is the same fixation attack regenerating the
     * session id defends against, one layer up.
     *
     * The cost is the honest one: a form open in another tab, filled in before
     * the login, will be refused when it is finally submitted. That is the
     * right way round. The alternative is a token that outlives the identity
     * it was issued under.
     *
     * From here to the end of the request, token() answers with the new one,
     * so a page rendered after a login carries the token its cookie will.
     */
    public function rotate(): string
    {
        return $this->issued = $this->signer->sign(Signer::token(), self::CONTEXT);
    }

    /** The cookie carrying that token back to the browser. */
    public function cookie(string $token, bool $secure): Cookie
    {
        return new Cookie(
            name: $this->nameFor($secure),
            value: $token,
            expires: \time() + $this->lifetime,
            secure: $secure,
            // The one cookie in the framework that is not HttpOnly, for the
            // reason given on the constant.
            httpOnly: false,
            sameSite: 'Lax',
        );
    }

    /**
     * Did this request prove where it came from?
     *
     * Returns a reason rather than a bool, because "CSRF token mismatch" is one
     * of the least helpful errors a framework produces and the four ways to
     * arrive at it need completely different fixes: the form is missing a
     * field, cookies are being dropped, the token is stale, or the request
     * really is cross-site.
     */
    public function check(Request $request): ?string
    {
        if (!$this->protects($request->method())) {
            return null;
        }

        if ($this->checkOrigin) {
            $origin = $this->originMismatch($request);

            if ($origin !== null) {
                return $origin;
            }
        }

        $name = $this->cookieName($request);
        $cookie = $request->cookie($name);

        if ($cookie === null) {
            return 'This request carried no ' . $name . ' cookie. Either cookies are being '
                . 'dropped, or the form was rendered before the cookie was issued.';
        }

        $submitted = $this->submitted($request);

        if ($submitted === null) {
            return \sprintf(
                'This request carried no CSRF token. A form needs a %s field; a script needs an %s header.',
                self::FIELD,
                self::HEADERS[0],
            );
        }

        if ($this->signer->verify($cookie, self::CONTEXT) === null) {
            return 'The ' . $name . ' cookie is not signed by this application. It may have '
                . 'been set by another site on this domain, or APP_KEY may have changed.';
        }

        if (!Signer::matches($cookie, $submitted)) {
            return 'The submitted CSRF token does not match the cookie. The page is probably older '
                . 'than the cookie, which is what an expired form looks like.';
        }

        return null;
    }

    private function nameFor(bool $secure): string
    {
        return $secure && $this->hostPrefix ? self::SECURE_COOKIE : self::COOKIE;
    }

    /** The token as the request supplied it, from a field or a header. */
    private function submitted(Request $request): ?string
    {
        /** @var mixed $field */
        $field = $request->input(self::FIELD);

        if (\is_string($field) && $field !== '') {
            return $field;
        }

        foreach (self::HEADERS as $header) {
            $value = $request->header($header);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A cross-origin unsafe request, named.
     *
     * Origin is checked and Referer is not. Referer is stripped by proxies,
     * privacy extensions and referrer policies, so treating its absence as
     * suspicious produces false rejections; Origin is sent by every browser
     * that matters for exactly these requests and cannot be set by script.
     * Absent means "cannot tell", and the token check still has to pass.
     */
    private function originMismatch(Request $request): ?string
    {
        $origin = $request->header('Origin');

        if ($origin === null || $origin === '' || $origin === 'null') {
            return null;
        }

        $host = \parse_url($origin, \PHP_URL_HOST);
        $expected = $request->host();

        if (!\is_string($host) || \strcasecmp($host, $expected) === 0) {
            return null;
        }

        return \sprintf(
            'This request came from %s, which is not %s. A browser will not send this Origin for '
            . 'a request your own pages made.',
            $host,
            $expected,
        );
    }
}
