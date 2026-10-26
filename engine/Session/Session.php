<?php

declare(strict_types=1);

namespace App\Engine\Session;

/**
 * The session, as a handler sees it.
 *
 * Type-hint it and the container hands you this request's session; there is no
 * $_SESSION, no session_start(), and no global to reach for. That is not
 * purism. PHP's own session machinery keeps the data in a superglobal, the id
 * in engine state, and the configuration in ini settings, which together mean a
 * session cannot be constructed in a test, cannot be swapped for a fake, and
 * cannot be reasoned about without knowing what the SAPI did before the script
 * ran.
 *
 * **This object records changes, it does not write them.** Every set() and
 * forget() goes into a change log, and the log -- not the whole array -- is
 * what gets merged into storage at the end of the request. See SessionStore for
 * why that is the difference between two concurrent requests both working and
 * one of them silently losing.
 *
 * **Flash data lives in the payload like anything else.** flash('status', ...)
 * sets an ordinary key and adds its name to a list of things to delete one
 * request later, so reading it is just get('status'). The alternative -- a
 * separate flash bag with its own accessors -- means every template that shows
 * a message has to know which kind of value it is.
 *
 * The ageing rule is worth knowing because it fixes a bug people usually live
 * with: flash data ages on requests that TOUCH the session, and this session is
 * lazy. A background poll that never reads the session does not eat the message
 * meant for the next page the user opens.
 */
final class Session
{
    /**
     * Where the two flash lists live.
     *
     * Reserved, and refused to callers: set('_flash', ...) would corrupt the
     * ageing and the failure would look like "my flash messages are random".
     */
    public const FLASH_KEY = '_flash';

    /**
     * Keys beginning with this belong to the framework, and application code
     * cannot write them.
     *
     * It started as one reserved key for the flash bookkeeping. It is a rule
     * because of the second one: authentication keeps the current account's id
     * in the session, and if `set()` could write it, then **any code that can
     * put a value in the session could log itself in as anybody** -- a
     * user-supplied key reaching a `fill()` would be enough. Framework code
     * writes these through setReserved(), which says so at the call site.
     */
    public const RESERVED_PREFIX = '_';

    /** @var array<string, mixed> */
    private array $payload;

    /** @var array<string, mixed> keys written this request */
    private array $sets = [];

    /** @var array<string, true> keys removed this request */
    private array $unsets = [];

    private bool $regenerated = false;

    /**
     * Set by clear(), and the one thing not expressed as a per-key change.
     *
     * "Empty the session" cannot be a list of keys, because the list would only
     * cover the keys THIS request happened to know about. A key written
     * concurrently elsewhere would survive a clear() -- and since invalidate()
     * is built on clear(), logging out could leave data behind.
     */
    private bool $cleared = false;

    /**
     * @param array<string, mixed>            $payload
     * @param \Closure(bool, bool): string|null $regenerator what the manager does
     *                                                     when asked for a new id
     */
    public function __construct(
        private string $id,
        array $payload = [],
        private readonly bool $existed = false,
        private readonly ?\Closure $regenerator = null,
    ) {
        $this->payload = $payload;
        $this->ageFlash();
    }

    public function id(): string
    {
        return $this->id;
    }

    /** False when this request started a new session rather than resuming one. */
    public function existed(): bool
    {
        return $this->existed;
    }

    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    // ---- reading ---------------------------------------------------------

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->payload) && $this->payload[$key] !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /** @return array<string, mixed> everything except the bookkeeping */
    public function all(): array
    {
        $payload = $this->payload;
        unset($payload[self::FLASH_KEY]);

        return $payload;
    }

    // ---- writing ---------------------------------------------------------

    public function set(string $key, mixed $value): void
    {
        $this->guard($key);

        $this->payload[$key] = $value;
        $this->sets[$key] = $value;
        unset($this->unsets[$key]);
    }

    /** @param array<string, mixed> $values */
    public function fill(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function forget(string $key): void
    {
        $this->guard($key);

        unset($this->payload[$key], $this->sets[$key]);
        $this->unsets[$key] = true;
    }

    /** Read it and remove it, which is what a one-shot value wants. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);

        if (\array_key_exists($key, $this->payload)) {
            $this->forget($key);
        }

        return $value;
    }

    /**
     * Empty the session, keeping the id.
     *
     * Note what this is NOT: logging somebody out. That needs the id to change
     * as well, or an attacker who already knows the id is still holding a valid
     * one -- see invalidate().
     */
    public function clear(): void
    {
        $this->payload = [];
        $this->sets = [];
        $this->unsets = [];
        $this->cleared = true;
    }

    // ---- the framework's own keys ----------------------------------------

    /**
     * Read a framework-owned key.
     *
     * Not private, because the framework is not one class: the session is
     * written here and read by the authentication layer, which is a different
     * subsystem and deliberately not a friend of this one. Public with a name
     * that says what it is beats a getter called get() that quietly behaves
     * differently for some keys.
     */
    public function reserved(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function setReserved(string $key, mixed $value): void
    {
        $this->payload[$key] = $value;
        $this->sets[$key] = $value;
        unset($this->unsets[$key]);
    }

    public function forgetReserved(string $key): void
    {
        unset($this->payload[$key], $this->sets[$key]);
        $this->unsets[$key] = true;
    }

    // ---- flash -----------------------------------------------------------

    /** Available to this request and the next one, then gone. */
    public function flash(string $key, mixed $value): void
    {
        $this->set($key, $value);
        $this->flashNext(\array_merge($this->flashList('new'), [$key]));
    }

    /**
     * Available to this request only.
     *
     * For the case where the handler has the message and the template is about
     * to render it -- putting it in storage for a request that will never come
     * is just a write.
     */
    public function now(string $key, mixed $value): void
    {
        $this->guard($key);

        $this->payload[$key] = $value;
    }

    /** Keep everything flashed to this request for one more. */
    public function reflash(): void
    {
        $this->flashNext(\array_values(\array_unique(
            \array_merge($this->flashList('new'), $this->flashList('old')),
        )));
    }

    /** Keep some of it. */
    public function keep(string ...$keys): void
    {
        $this->flashNext(\array_values(\array_unique(
            \array_merge($this->flashList('new'), $keys),
        )));
    }

    // ---- the id ----------------------------------------------------------

    /**
     * A new id for the same session.
     *
     * This is the defence against session fixation: an attacker who planted an
     * id in the victim's browser -- through a link, an injected cookie, a
     * shared machine -- holds one that stopped meaning anything the moment the
     * user's privileges changed. Phase 25 calls this on login; anything that
     * elevates what a session can do should call it too.
     *
     * The old id is not destroyed outright unless $followable is false. See
     * SessionManager::regenerate() for the grace window, which is what stops a
     * request already in flight from losing its session -- and why a change of
     * privilege, a login above all, must pass $followable = false.
     */
    public function regenerate(bool $keepData = true, bool $followable = true): string
    {
        if (!$keepData) {
            $this->clear();
        }

        if ($this->regenerator !== null) {
            $this->id = ($this->regenerator)($keepData, $followable);
        }

        $this->regenerated = true;

        return $this->id;
    }

    /** Log out: drop the data AND change the id, in that order. */
    public function invalidate(): string
    {
        return $this->regenerate(keepData: false);
    }

    // ---- what the manager needs -----------------------------------------

    /**
     * Everything this session holds, bookkeeping included.
     *
     * Only one caller, and it is the case the change log cannot express: after
     * regenerate(), the new id has no stored record to merge into, so there is
     * nothing for a list of changes to be applied TO. The session itself is
     * what the new record has to start from -- including the keys this request
     * only read, which is most of them and is the whole point of keeping the
     * data across a regeneration.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->payload;
    }

    public function isDirty(): bool
    {
        return $this->cleared || $this->sets !== [] || $this->unsets !== [];
    }

    /**
     * Fold this request's changes into whatever is in storage now.
     *
     * The merge is deliberately ordered: removals last, so that a key both set
     * and forgotten in one request ends up gone. set() and forget() already
     * keep the logs disjoint, and this is the belt to that braces.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function mergeInto(array $stored): array
    {
        // clear() is a whole-session operation, so it discards what is there
        // rather than unsetting the keys this request knew about.
        if ($this->cleared) {
            $stored = [];
        }

        foreach ($this->sets as $key => $value) {
            $stored[$key] = $value;
        }

        foreach (\array_keys($this->unsets) as $key) {
            unset($stored[$key]);
        }

        return $stored;
    }

    // ---- internals -------------------------------------------------------

    /**
     * Delete what was flashed two requests ago, and promote what was flashed
     * one request ago.
     *
     * Done in the constructor rather than at save time on purpose: by the time
     * the handler runs, the previous request's flash data must already be
     * readable and the one before it must already be gone.
     */
    private function ageFlash(): void
    {
        $expiring = $this->flashList('old');
        $current = $this->flashList('new');

        foreach ($expiring as $key) {
            if (!\in_array($key, $current, true)) {
                unset($this->payload[$key]);
                $this->unsets[$key] = true;
            }
        }

        if ($expiring === [] && $current === []) {
            return;
        }

        $this->writeFlash(['old' => $current, 'new' => []]);
    }

    /** @return list<string> */
    private function flashList(string $which): array
    {
        $flash = $this->payload[self::FLASH_KEY] ?? [];

        if (!\is_array($flash) || !isset($flash[$which]) || !\is_array($flash[$which])) {
            return [];
        }

        $keys = [];

        foreach ($flash[$which] as $key) {
            if (\is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Set what survives into the next request, leaving what this one can still
     * read alone.
     *
     * Only the "new" list is ever written from outside ageFlash(), because
     * nothing can usefully change what the PREVIOUS request flashed.
     *
     * @param list<string> $keys
     */
    private function flashNext(array $keys): void
    {
        $this->writeFlash(['old' => $this->flashList('old'), 'new' => $keys]);
    }

    /** @param array{old: list<string>, new: list<string>} $flash */
    private function writeFlash(array $flash): void
    {
        $this->payload[self::FLASH_KEY] = $flash;
        $this->sets[self::FLASH_KEY] = $flash;
        unset($this->unsets[self::FLASH_KEY]);
    }

    private function guard(string $key): void
    {
        if ($key === '') {
            throw SessionException::emptyKey();
        }

        if (\str_starts_with($key, self::RESERVED_PREFIX)) {
            throw SessionException::reservedKey($key);
        }
    }
}
