<?php

declare(strict_types=1);

namespace App\Modules\Files\Ftp;

use App\Engine\Auth\Identity;

/**
 * Where FTP hooks into the other modules.
 */
final class FtpListeners
{
    public function __construct(
        private readonly FtpUsers $users,
        private readonly FtpSync $sync,
    ) {}

    /**
     * accounts.changed: suspending shuts the account's logins out (and ends
     * their sessions); unsuspending lets them in again. Terminate took them
     * away on the server already (the agent does it before deleting the
     * user), so only the rows go.
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        $id = (int) $row['id'];

        match ($op) {
            'account.terminate' => $this->sync->forget($id),
            'account.restore' => $this->sync->touch($id),
            'account.suspend', 'account.unsuspend' => $this->users->count($id) > 0 || $this->sync->stateOf($id) !== null
                ? $this->sync->touch($id)
                : null,
            default => null,
        };
    }

    /**
     * accounts.show: the account's FTP logins, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $cards[] = ['template' => '@Files/ftp-card', 'data' => [
            'ftp_users' => $this->users->users((int) $account['id']),
            'ftp_state' => $this->sync->stateOf((int) $account['id']),
        ]];

        return $cards;
    }
}
