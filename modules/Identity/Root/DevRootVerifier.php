<?php

declare(strict_types=1);

namespace App\Modules\Identity\Root;

use App\Engine\Security\Secret;

/**
 * For a developer's machine, which has no authd: the root password is
 * LPANEL_DEV_ROOT_PASSWORD. It refuses everything unless APP_ENV is "local",
 * so a stray setting can never open a real server.
 */
final class DevRootVerifier implements RootPasswordVerifier
{
    public function __construct(
        private readonly string $environment,
        private readonly string $password,
    ) {}

    public function check(Secret $password, ?string $ip): RootCheck
    {
        if ($this->environment !== 'local' || \strlen($this->password) < 8) {
            return RootCheck::unavailable();
        }

        return \hash_equals($this->password, $password->reveal()) ? RootCheck::allowed() : RootCheck::denied();
    }
}
