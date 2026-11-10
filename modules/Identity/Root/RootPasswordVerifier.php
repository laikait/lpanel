<?php

declare(strict_types=1);

namespace App\Modules\Identity\Root;

use App\Engine\Security\Secret;

/**
 * Checks the server's root password. The panel never stores or sees a hash of
 * it: on a server the check is lpanel-authd's, which asks PAM (ADR-0004).
 */
interface RootPasswordVerifier
{
    public function check(Secret $password, ?string $ip): RootCheck;
}
