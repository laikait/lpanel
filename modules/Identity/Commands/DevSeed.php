<?php

declare(strict_types=1);

namespace App\Modules\Identity\Commands;

use App\Engine\Auth\Password;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Security\Secret;
use App\Modules\Identity\Auth\DevRootVerifier;
use App\Modules\Identity\Users\Roles;
use App\Modules\Identity\Users\UserStore;

/**
 * dev:seed -- sample accounts to click around with on a developer's machine.
 *
 * Refuses unless app.env is local or testing: the passwords are printed here
 * and written in DEVELOPMENT.md, so they must never exist on a server. An
 * account that is already there is left alone.
 */
final class DevSeed
{
    /** login => [name, email, role, password] */
    public const ACCOUNTS = [
        'admin' => ['Alice Admin', 'admin@lpanel.test', Roles::ADMIN, 'admin-password-123'],
        'customer' => ['Carl Customer', 'customer@lpanel.test', Roles::CUSTOMER, 'customer-password-123'],
    ];

    public function __construct(
        private readonly UserStore $users,
        private readonly Password $passwords,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output): int
    {
        $env = (string) $this->config->string('app.env', 'production');

        if (!\in_array($env, DevRootVerifier::ENVIRONMENTS, true)) {
            $output->line(\sprintf('dev:seed refuses to run where app.env is "%s": its passwords are public.', $env));

            return 1;
        }

        foreach (self::ACCOUNTS as $login => [$name, $email, $role, $password]) {
            if ($this->users->byLogin($login) !== null) {
                $output->line(\sprintf('  %-9s already exists, left as it is', $login));

                continue;
            }

            $this->users->create($login, $name, $email, $this->passwords->hash(new Secret($password)), [$role]);
            $output->line(\sprintf('  %-9s %-22s %s', $login, $password, $role));
        }

        $output->line('Root signs in with the password whose hash is in LPANEL_DEV_ROOT_PASSWORD_HASH.');

        return 0;
    }
}
