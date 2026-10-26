<?php

declare(strict_types=1);

namespace App\Engine\Session;

use App\Engine\Hook\HookEngine;
use App\Engine\Http\Cookie;
use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * Policy: when a session is loaded, when it is written, and what the cookie
 * looks like. The store knows none of that, and the Session knows none of it
 * either.
 *
 * **Nothing is loaded until something asks.** A request that never touches the
 * session does no storage read, no storage write and sets no cookie. That is
 * not an optimisation, it is what keeps a JSON API from handing out session
 * cookies to clients that will never send them back, and it is why flash data
 * survives a background poll (see Session).
 *
 * The consequence worth stating: there is no "current session" on a request
 * that did not use one, and active() answers honestly rather than starting one
 * to be able to say yes.
 *
 * **Two listeners, no middleware.** onRequest() on request.received remembers
 * which request is being served; onResponse() on response.instance writes the
 * session and attaches the cookie. Between them the handler does whatever it
 * likes, including nothing.
 */
final class SessionManager
{
    public const DEFAULT_COOKIE = 'session';

    /** Two hours of inactivity, which is the number most people expect. */
    public const DEFAULT_IDLE = 7200;

    /**
     * How long a regenerated id keeps working.
     *
     * Short, because it is an id the application has decided to stop trusting,
     * and long enough for requests already on the wire. See regenerate().
     */
    public const DEFAULT_GRACE = 30;

    private ?Request $request = null;

    private ?Session $session = null;

    /** Carried across regeneration so the absolute lifetime is not reset by it. */
    private int $createdAt = 0;

    public function __construct(
        private readonly SessionStore $store,
        private readonly ?HookEngine $hooks = null,
        private readonly string $cookieName = self::DEFAULT_COOKIE,
        private readonly int $idle = self::DEFAULT_IDLE,
        private readonly int $absolute = 0,
        private readonly int $grace = self::DEFAULT_GRACE,
        private readonly int $cookieLifetime = 0,
        private readonly string $cookiePath = '/',
        private readonly string $cookieDomain = '',
        private readonly string $sameSite = 'Lax',
        private readonly ?bool $secure = null,
    ) {}

    public function store(): SessionStore
    {
        return $this->store;
    }

    public function describe(): string
    {
        return $this->store->describe();
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function idle(): int
    {
        return $this->idle;
    }

    public function absolute(): int
    {
        return $this->absolute;
    }

    /** request.received: remember what is being served, and read nothing. */
    public function onRequest(Request $request): void
    {
        $this->request = $request;
        $this->session = null;
        $this->createdAt = 0;
    }

    /** True when something has actually used the session this request. */
    public function active(): bool
    {
        return $this->session !== null;
    }

    /**
     * Whether this request even claims to have a session.
     *
     * For code that wants to look at a session only if there already is one --
     * the authentication layer is the caller that matters. Without it,
     * authenticating would mean calling session(), which STARTS one, and every
     * anonymous request to a public page would acquire a session cookie it
     * never uses. That would undo the laziness this whole class is built
     * around.
     *
     * It answers from the cookie alone and does not read storage, so a cookie
     * naming a session that has expired still answers true; the caller finds
     * out a moment later, from a session that has nothing in it.
     */
    public function isResumable(?Request $request = null): bool
    {
        if ($this->session !== null) {
            return true;
        }

        $id = ($request ?? $this->request)?->cookie($this->cookieName);

        return $id !== null && SessionId::isValid($id);
    }

    /**
     * This request's session, loading it the first time it is asked for.
     *
     * Works without a request, which is what makes a session usable from the
     * console: a command that has to touch a user's session gets a fresh one
     * rather than an exception about there being no cookie.
     */
    public function session(): Session
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $record = $this->load();
        $now = \time();

        $this->createdAt = $record->createdAt ?? $now;

        $this->session = new Session(
            $record->id ?? SessionId::generate(),
            $record->payload ?? [],
            $record !== null,
            fn(bool $keepData, bool $followable = true): string => $this->regenerate($keepData, $followable),
        );

        $this->hooks?->do('session.started', $this->session);

        return $this->session;
    }

    /**
     * A new id, with the old one left as a signpost for a few seconds.
     *
     * The naive version -- write the new id, delete the old -- breaks a page
     * that logs in and has three fetches already in flight: each of them
     * arrives holding an id that no longer exists, gets a brand new empty
     * session, and the user is logged out by the act of logging in. So the old
     * record is replaced by a pointer with no data in it, which a request
     * arriving inside the grace window follows exactly once.
     *
     * **A pointer is a way in.** Following it hands the request the new id,
     * and a request holding the old id cannot be told apart from an attacker
     * holding it -- which is exactly who session fixation is about. So
     * $followable = false, for a change of privilege such as a login, leaves
     * no pointer at all and the old id stops working at once; the cost is the
     * in-flight request above starting an empty session. The grace window is
     * for regenerations that do not change who the session belongs to.
     */
    public function regenerate(bool $keepData = true, bool $followable = true): string
    {
        $previous = $this->session?->id();
        $next = SessionId::generate();

        if ($previous !== null && $previous !== $next) {
            $now = \time();

            $this->store->commit(
                $previous,
                static fn(?SessionRecord $current): ?SessionRecord => $keepData && $followable
                    ? ($current ?? SessionRecord::fresh($previous, [], $now))->replacedBy($next, $now)
                    : null,
            );

            $this->hooks?->do('session.regenerated', $next, $previous);
        }

        return $next;
    }

    /**
     * response.instance: write what changed and hand back the cookie.
     *
     * Every request that touched the session writes, even one that only read.
     * Reading is activity, and a session that did not record it would log out a
     * user who is plainly still there.
     */
    public function onResponse(Response $response, ?Request $request = null): Response
    {
        if ($this->session === null) {
            return $response;
        }

        $session = $this->session;
        $this->save();

        $incoming = ($request ?? $this->request)?->cookie($this->cookieName);

        if ($incoming !== null && $incoming === $session->id()) {
            return $response;
        }

        return $response->withCookie($this->cookie($session->id(), $request ?? $this->request));
    }

    /**
     * Merge this request's changes into storage.
     *
     * The closure runs inside the store's lock, which is why it takes the
     * stored record as an argument instead of the manager reading it first:
     * anything read out here would already be stale by the time it was written
     * back. See SessionStore.
     */
    public function save(): void
    {
        if ($this->session === null) {
            return;
        }

        $session = $this->session;
        $now = \time();
        $createdAt = $this->createdAt > 0 ? $this->createdAt : $now;

        $this->store->commit($session->id(), static function (?SessionRecord $current) use (
            $session,
            $now,
            $createdAt,
        ): SessionRecord {
            // After a regeneration the id is brand new, so there is no stored
            // record for a list of changes to be applied to. The session is
            // what the new record starts from -- otherwise everything this
            // request only READ would be dropped, and keeping the data across a
            // regeneration is the whole point of keeping the data.
            //
            // Otherwise: whatever is stored now, which is deliberately not what
            // this request read at the start. A pointer's payload is empty by
            // construction and must not be merged into.
            $base = $session->wasRegenerated()
                ? $session->snapshot()
                : ($current === null || $current->isPointer() ? [] : $current->payload);

            $payload = $session->mergeInto($base);

            return $current === null || $current->isPointer()
                ? new SessionRecord($session->id(), $payload, $createdAt, $now)
                : $current->withPayload($payload, $now);
        });
    }

    /** Throw the session away entirely: storage, object and cookie. */
    public function destroy(): void
    {
        if ($this->session !== null) {
            $this->store->destroy($this->session->id());
        }

        $this->session = null;
    }

    /** The cookie that tells the browser to drop it. */
    public function forgetCookie(): Cookie
    {
        return Cookie::forget($this->cookieName, $this->cookiePath, $this->cookieDomain);
    }

    public function cookie(string $id, ?Request $request = null): Cookie
    {
        return new Cookie(
            name: $this->cookieName,
            value: $id,
            // Zero means a browser-session cookie: it goes when the browser
            // does. That is the safer default and the one people expect; a
            // cookie that outlives the browser is a "remember me", which is a
            // decision about identity rather than about storage.
            expires: $this->cookieLifetime > 0 ? \time() + $this->cookieLifetime : 0,
            path: $this->cookiePath,
            domain: $this->cookieDomain,
            secure: $this->secure ?? ($request ?? $this->request)?->isSecure() ?? false,
            // Never configurable. This cookie IS the credential, and script has
            // no business reading it; the CSRF cookie is the readable one
            // precisely because it is not a credential.
            httpOnly: true,
            sameSite: $this->sameSite,
        );
    }

    public function gc(): int
    {
        return $this->store->gc($this->idle, $this->absolute);
    }

    /**
     * Find the record this request's cookie points at, if it still means
     * anything.
     *
     * Four ways to end up with nothing, all of which mean "start fresh": no
     * cookie, an id that this framework did not issue, an id storage has never
     * heard of, and an id that has expired.
     */
    private function load(): ?SessionRecord
    {
        $id = $this->request?->cookie($this->cookieName);

        if ($id === null || !SessionId::isValid($id)) {
            return null;
        }

        $record = $this->store->read($id);

        if ($record !== null && $record->isPointer()) {
            $record = $this->follow($record);
        }

        if ($record === null || $record->isPointer()) {
            return null;
        }

        return $record->hasExpired($this->idle, $this->absolute) ? null : $record;
    }

    /**
     * Follow a regeneration pointer, exactly once.
     *
     * Once, not "until it stops pointing": a chain would let one stale id walk
     * through every regeneration a session ever had, which is the opposite of
     * what regenerating is for. A request older than one regeneration has been
     * in flight longer than the grace window and starts fresh.
     */
    private function follow(SessionRecord $pointer): ?SessionRecord
    {
        // No grace is no grace, not an unlimited one.
        if ($this->grace <= 0 || $pointer->touchedAt + $this->grace <= \time()) {
            return null;
        }

        $successor = $pointer->successor;

        if ($successor === null) {
            return null;
        }

        $record = $this->store->read($successor);

        return $record !== null && !$record->isPointer() ? $record : null;
    }
}
