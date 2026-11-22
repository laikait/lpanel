<?php

declare(strict_types=1);

namespace App\Modules\Files\Ssh;

use App\Engine\Auth\Identity;

/**
 * Where SSH keys hook into the other modules.
 */
final class SshListeners
{
    public function __construct(
        private readonly SshKeys $keys,
        private readonly SshSync $sync,
        private readonly SshActions $actions,
    ) {}

    /**
     * accounts.changed: terminate took the keys away on the server already
     * (the agent does it before deleting the user), so only the rows go.
     * Suspension needs nothing here: an expired account cannot log in with
     * any key.
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        match ($op) {
            'account.terminate' => $this->sync->forget((int) $row['id']),
            // Made again from a backup (Backups): its keys, on the server.
            'account.restore' => $this->sync->touch((int) $row['id']),
            default => null,
        };
    }

    /**
     * accounts.show: the account's SSH access and keys, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $cards[] = ['template' => 'Files/ssh-card', 'data' => [
            'ssh_access' => $this->actions->access($account),
            'ssh_keys' => $this->keys->keys((int) $account['id']),
            'ssh_state' => $this->sync->stateOf((int) $account['id']),
        ]];

        return $cards;
    }
}
