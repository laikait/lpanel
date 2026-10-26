<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Auth\Password;
use App\Engine\Security\Secret;

/**
 * Root's password from a hash in config, for a developer's machine without
 * PAM (Windows, XAMPP). The module refuses to build it unless app.env is local
 * or testing, so it cannot reach production by accident.
 */
final class DevRootVerifier implements RootVerifier
{
    public const ENVIRONMENTS = ['local', 'testing'];

    public function __construct(private readonly Password $passwords, private readonly string $hash) {}

    public function describe(): string
    {
        return 'development root password (Identity.root.dev_password_hash)';
    }

    public function verify(string $user, Secret $password, string $ip): RootVerdict
    {
        return $user === 'root' && $this->hash !== '' && $this->passwords->verify($password, $this->hash)
            ? RootVerdict::accepted()
            : RootVerdict::refused(RootVerdict::DENIED);
    }
}
