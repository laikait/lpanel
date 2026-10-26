<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;

/**
 * Where the security layer meets a request, and the answer to "but there is no
 * middleware".
 *
 * Four listeners on hooks the kernel already fires:
 *
 *   request.received    the size limit, before anything reads a body
 *   dispatch.before     CSRF and the rate limit, with the route in hand
 *   response.instance   the CSRF cookie, on the way out
 *   session.regenerated rotate the token when the identity changed
 *
 * This is what the specification's ban on middleware asks for and it is not a
 * workaround. A middleware stack is a pipeline every request walks whether or
 * not each layer has anything to say, ordered by a list somebody maintains, and
 * the usual failure is a layer that silently stopped running because it was
 * registered in the wrong place. Here the seams are named events, ordering is
 * a priority number, and **a listener refuses by throwing an HttpException** --
 * which the kernel already knows how to turn into a response, because that is
 * how 404 and 405 work.
 *
 * The cost is stated plainly: a listener cannot wrap the handler, so there is
 * no "do this after the response comes back, in the same closure". Nothing
 * here needs to; the response filter covers the other half.
 *
 * **CSRF is on unless a route opts out.** `meta(['csrf' => false])` for
 * endpoints authenticated by a bearer token rather than a cookie, where CSRF
 * has nothing to protect. Opt-out is the safe direction: the route somebody
 * adds in a hurry is protected by default, and it is reliably the one that
 * matters.
 *
 * **Rate limiting is off unless a route asks.** The opposite default, for the
 * opposite reason: a limit needs a number and a key that only the application
 * knows, and a framework-chosen one would either be too high to help or too low
 * to survive a real client.
 */
final class Guard
{
    public const CSRF_META = 'csrf';

    public const RATE_LIMIT_META = 'rate_limit';

    /** meta(['signed' => true]): only a link made by SignedUrl gets in. */
    public const SIGNED_META = 'signed';

    public function __construct(
        private readonly Csrf $csrf,
        private readonly RateLimiter $limiter,
        private readonly RequestLimits $limits,
        private readonly bool $csrfEnabled = true,
        private readonly ?SignedUrl $signedUrls = null,
    ) {}

    /**
     * request.received: how much a request is allowed to be.
     *
     * And the start of a new request for the CSRF token. Guard and Csrf outlive
     * a request in a worker, so anything per-request has to be reset rather
     * than assumed fresh -- here, the token issued to the previous browser.
     */
    public function onRequest(Request $request): void
    {
        $this->csrf->begin();

        ($this->limits)($request);
    }

    /**
     * session.regenerated: issue a new CSRF token to go with the new id.
     *
     * A hook rather than a dependency, so that this class still knows nothing
     * about sessions -- it is told that something happened, and what it does
     * about it is its own business. The parameters are the hook's, and unused
     * here: what matters is that it happened, not which id replaced which.
     */
    public function onSessionRegenerated(): void
    {
        $this->csrf->rotate();
    }

    /**
     * dispatch.before: the checks that need to know which route was matched.
     *
     * The rate limit is applied before CSRF deliberately. A client hammering an
     * endpoint with bad tokens is exactly what a limit is for, and checking
     * CSRF first would mean the expensive-to-abuse path is the one with no
     * limit on it.
     */
    public function onDispatch(Route $route, Request $request): void
    {
        $this->enforceRateLimit($route, $request);

        // After the limit, so guessing signatures is throttled too.
        if ($route->metaValue(self::SIGNED_META) === true) {
            ($this->signedUrls ?? throw SecurityException::keyNotSet())->verify($request);
        }

        $this->enforceCsrf($route, $request);
    }

    /**
     * response.instance: hand the browser a token to send back next time.
     *
     * Issued on every response that does not already have a valid one, rather
     * than only on pages with forms -- the framework cannot see a form, and a
     * page that acquires one after a JavaScript render would otherwise have no
     * token to use.
     */
    public function onResponse(Response $response, ?Request $request = null): Response
    {
        if (!$this->csrfEnabled || $request === null) {
            return $response;
        }

        // The token this request already issued or rotated to, if it did --
        // the one any form on this page carries -- or else the browser's own.
        $token = $this->csrf->token($request);

        if ($request->cookie($this->csrf->cookieName($request)) === $token) {
            return $response;
        }

        return $response->withCookie($this->csrf->cookie($token, $request->isSecure()));
    }

    private function enforceCsrf(Route $route, Request $request): void
    {
        if (!$this->csrfEnabled || $route->metaValue(self::CSRF_META) === false) {
            return;
        }

        $reason = $this->csrf->check($request);

        if ($reason !== null) {
            throw HttpException::forbidden($reason);
        }
    }

    private function enforceRateLimit(Route $route, Request $request): void
    {
        /** @var mixed $limit */
        $limit = $route->metaValue(self::RATE_LIMIT_META);

        if (!\is_string($limit) || $limit === '') {
            return;
        }

        $result = $this->limiter->attempt($this->keyFor($route, $request), $limit);

        if (!$result->allowed) {
            throw HttpException::tooManyRequests($result->retryAfter, $result->headers());
        }
    }

    /**
     * One route, one client.
     *
     * Scoped to the route rather than to the whole site, so that a limit on the
     * login form does not also stop the same office reading the catalogue. The
     * client is the request's ip(), which already honours trusted proxies --
     * and which is null behind a proxy nobody configured, in which case every
     * unidentifiable client shares one bucket. That is the safe direction to
     * fail: a shared bucket over-limits, and the alternative would be no limit
     * at all for precisely the requests whose origin cannot be established.
     */
    private function keyFor(Route $route, Request $request): string
    {
        return 'route:' . ($route->routeName() ?? $route->method() . ' ' . $route->path())
            . '|' . ($request->ip() ?? 'unknown');
    }
}
