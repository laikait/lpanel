<?php

declare(strict_types=1);

namespace App\Engine\Auth\Providers;

use App\Engine\Auth\Account;
use App\Engine\Auth\UserProvider;

/**
 * The provider an application has when it has not said where its users are.
 *
 * Every lookup answers null, so every request is a guest and every route that
 * requires a login answers 401. That is the correct behaviour for an
 * application with no user store, not a silent failure -- and it is why this
 * exists at all rather than the framework refusing to boot without a provider:
 * most of this framework's subsystems are useful to an application that never
 * logs anybody in, and none of them should be unreachable until one does.
 *
 * Compare the scheduler, which deliberately ships **no** null lock: a lock that
 * locks nothing looks like it is working and is not. A provider with no users
 * is not pretending anything. `describe()` says exactly what it is, `about` and
 * `security:check` print it, and an application that meant to configure one
 * finds out from the first 401 rather than from a subtly wrong answer.
 */
final class EmptyProvider implements UserProvider
{
    public function describe(): string
    {
        return 'none configured (every request is a guest)';
    }

    public function byId(string $id): ?Account
    {
        return null;
    }

    public function byLogin(string $login): ?Account
    {
        return null;
    }
}
