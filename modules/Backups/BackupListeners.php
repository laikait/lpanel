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
     * panel.attention: the last finished run did not back everything up.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function attention(array $items, string $area, Identity $identity): array
    {
        if ($area !== 'admin' || !$this->authorizer->allows($identity, 'server.backups')) {
            return $items;
        }

        foreach ($this->runs->recent(5) as $run) {
            if ($run['state'] === BackupRuns::RUNNING) {
                continue;
            }

            if ($run['state'] !== BackupRuns::DONE) {
                $items[] = [
                    'tone' => $run['state'] === BackupRuns::FAILED ? 'bad' : 'warn',
                    'title' => 'Backups.attention_title',
                    'text' => $run['state'] === BackupRuns::FAILED ? 'Backups.attention_failed' : 'Backups.attention_partial',
                    'url' => $this->router->url('admin.backups'),
                ];
            }

            break;
        }

        return $items;
    }

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
        $cards[] = ['template' => 'Backups/card', 'data' => [
            'backup_last' => $this->runs->lastFor($username),
            'backup_now_url' => $this->router->url('admin.backups.run'),
            'backup_username' => $username,
            'backup_snapshots_url' => $this->router->url('admin.backups.account', ['username' => $username]),
        ]];

        return $cards;
    }
}
