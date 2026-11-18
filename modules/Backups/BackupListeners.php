<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Routing\Router;

/**
 * Where backups show in other modules' pages.
 */
final class BackupListeners
{
    public function __construct(
        private readonly BackupRuns $runs,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
    ) {}

    /**
     * accounts.show: the account's last backup, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        if (!$this->authorizer->allows($identity, 'server.backups')) {
            return $cards;
        }

        $username = (string) $account['username'];
        $cards[] = ['template' => '@Backups/card', 'data' => [
            'backup_last' => $this->runs->lastFor($username),
            'backup_now_url' => $this->router->url('admin.backups.run'),
            'backup_username' => $username,
            'backup_snapshots_url' => $this->router->url('admin.backups.account', ['username' => $username]),
        ]];

        return $cards;
    }
}
