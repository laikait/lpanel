<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Routing\Route;

/**
 * Where authentication meets a route. One listener, on a hook the kernel
 * already fires.
 *
 * ```php
 * $routes->post('/invoices/{id}/void', ...)->meta([
 *     'auth' => true,
 *     'can' => 'invoice.void',
 * ]);
 * ```
 *
 * **Requiring a login is opt-in, and that is the opposite of CSRF.** The
 * reasoning is not inconsistent: CSRF protects a route's side effects and
 * costs a correctly-written client nothing, so defaulting it on is free. A
 * login defaults every page to private, including the home page, which means
 * the default gets switched off wholesale on the first day -- and a default
 * everybody disables protects nothing while looking like it does.
 *
 * What replaces it is visibility rather than hope: `route:list` shows what each
 * route requires, `auth:access` lists every capability and which routes ask for
 * it, and `security:check` **warns about routes that change something and
 * require nobody**. "Nobody thought about this one" becomes a thing you can
 * see rather than a thing you assume.
 *
 * **`can` implies `auth`.** A capability check on a guest is a login prompt --
 * writing both would be a chance to write only one.
 */
final class AuthGuard
{
    public const AUTH_META = 'auth';

    public const CAN_META = 'can';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Authorizer $authorizer,
    ) {}

    /** dispatch.before: the route is known, so what it needs is known. */
    public function onDispatch(Route $route, Request $request): void
    {
        $capabilities = self::capabilitiesFor($route);
        $needsLogin = $route->metaValue(self::AUTH_META) === true || $capabilities !== [];

        if (!$needsLogin) {
            return;
        }

        $identity = $this->auth->identity();

        if ($identity->isGuest()) {
            throw HttpException::unauthorized();
        }

        foreach ($capabilities as $capability) {
            // Every one of them, not any of them. A route listing two
            // capabilities is describing two things it does, and "either will
            // do" is a thing somebody should have to write out.
            $this->authorizer->authorize($identity, $capability);
        }
    }

    /**
     * What a route declares it needs, as a list.
     *
     * A string for the ordinary case and a list for the rare one, because
     * making every route write `['can' => ['x']]` to say one thing is the kind
     * of tax that gets a metadata key ignored.
     *
     * @return list<string>
     */
    public static function capabilitiesFor(Route $route): array
    {
        /** @var mixed $declared */
        $declared = $route->metaValue(self::CAN_META);

        if (\is_string($declared)) {
            return $declared === '' ? [] : [$declared];
        }

        if (!\is_array($declared)) {
            return [];
        }

        $capabilities = [];

        foreach ($declared as $capability) {
            if (\is_string($capability) && $capability !== '') {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /** Whether a route requires anybody at all. */
    public static function isProtected(Route $route): bool
    {
        return $route->metaValue(self::AUTH_META) === true || self::capabilitiesFor($route) !== [];
    }
}
