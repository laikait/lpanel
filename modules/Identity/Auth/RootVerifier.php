<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Security\Secret;

/** Checks root's system password. SocketRootVerifier in production; DevRootVerifier on a developer's machine. */
interface RootVerifier
{
    public function describe(): string;

    public function verify(string $user, Secret $password, string $ip): RootVerdict;
}
