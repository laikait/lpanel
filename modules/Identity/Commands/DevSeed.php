<?php

declare(strict_types=1);

namespace App\Modules\Identity\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Security\Secret;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;

/**
 * `php laika dev:seed`: one login of each kind for a developer's machine.
 * Refuses unless APP_ENV is "local"; the passwords are in DEVELOPMENT.md.
 */
final class DevSeed
{
    /** username => [kind, password, parent] */
    public const LOGINS = [
        'admin' => [Kind::Admin, 'admin-password-123', null],
        'reseller' => [Kind::Reseller, 'reseller-password-123', null],
        'demo' => [Kind::User, 'demo-password-123', 'reseller'],
        'demo.dev' => [Kind::Subuser, 'subuser-password-123', 'demo'],
    ];

    public function __construct(
        private readonly PanelUsers $users,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output): int
    {
        if ($this->config->get('app.env') !== 'local') {
            $output->error('dev:seed only runs with APP_ENV=local.');

            return 1;
        }

        foreach (self::LOGINS as $username => [$kind, $password, $parent]) {
            if ($this->users->find($username) !== null) {
                $output->line(\sprintf('  %-10s already there', $username));

                continue;
            }

            $parentId = $parent === null ? null : (int) ($this->users->find($parent)['id'] ?? 0);
            $this->users->create($kind, $username, \ucfirst($username), null, new Secret($password), $parentId ?: null);
            $output->line(\sprintf('  %-10s %-9s %s', $username, $kind->value, $password));
        }

        $output->success('Development logins ready. root signs in with LPANEL_DEV_ROOT_PASSWORD.');

        return 0;
    }
}
