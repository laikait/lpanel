<?php

declare(strict_types=1);

namespace App\Modules\Security\BruteForce;

use App\Engine\Http\Request;

/** The panel's own failed sign-ins, counted like the services'. */
final class Listeners
{
    public function __construct(private readonly BruteForce $bruteForce) {}

    public function signInFailed(string $login, ?Request $request = null): void
    {
        $ip = $request?->ip();

        if ($ip !== null) {
            $this->bruteForce->record($ip, 'panel');
        }
    }
}
