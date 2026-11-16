<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Routing\Router;
use App\Modules\Accounts\Accounts;
use App\Modules\Subusers\Subusers;

/**
 * Where the Mail module hooks into the others.
 */
final class MailListeners
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailDomains $domains,
        private readonly MailSync $sync,
        private readonly Accounts $accounts,
        private readonly Subusers $subusers,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
    ) {}

    /**
     * accounts.changed: a new account's main domain becomes a mail domain
     * (and gets its DKIM key); suspending stops its mailboxes logging in
     * and sending, while mail still arrives. Terminate removed the mail on
     * the server already (the agent does it before deleting the user).
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        $id = (int) $row['id'];

        match ($op) {
            'account.terminate' => $this->sync->forget($id),
            'account.create' => $this->webChanged($row),
            'account.suspend', 'account.unsuspend' => $this->sync->stateOf($id) !== null ? $this->sync->touch($id) : null,
            default => null,
        };
    }

    /**
     * web.changed: the account's domains changed (after DNS made their
     * zones, so their mail records can go in).
     *
     * @param array<string, mixed> $account
     */
    public function webChanged(array $account): void
    {
        $fresh = $this->accounts->byId((int) $account['id']) ?? $account;

        if ($this->domains->reconcile($fresh) || $this->sync->stateOf((int) $fresh['id']) === null) {
            $this->sync->touch((int) $fresh['id']);
        }
    }

    /**
     * packages.updated: email may have been turned on or off for the
     * package's accounts.
     *
     * @param array<string, mixed> $package
     */
    public function packageUpdated(array $package): void
    {
        foreach ($this->accounts->onPackage((int) $package['id']) as $account) {
            if ($this->sync->stateOf((int) $account['id']) !== null) {
                $this->sync->touch((int) $account['id']);
                $this->sync->publish((int) $account['id']);
            }
        }
    }

    /**
     * dns.zone_reset: a zone went back to its template; the mail records
     * of the account's domains go back in.
     *
     * @param array<string, mixed> $zone
     */
    public function zoneReset(array $zone): void
    {
        if ($zone['account_id'] !== null) {
            $this->sync->publish((int) $zone['account_id']);
        }
    }

    /**
     * accounts.show: the account's mail, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $cards[] = ['template' => '@Mail/admin-card', 'data' => [
            'mailboxes' => $this->mail->mailboxes((int) $account['id']),
            'forwarders' => \count($this->mail->forwarders((int) $account['id'])),
            'mail_domains' => $this->mail->domains((int) $account['id']),
            'mail_state' => $this->sync->stateOf((int) $account['id']),
        ]];

        return $cards;
    }

    /**
     * panel.dashboard: the owner's mailboxes, on the User panel.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area !== 'user' || !$this->authorizer->allows($identity, 'feature.email')) {
            return $cards;
        }

        $account = $this->subusers->accountOf($identity);

        if ($account === null) {
            return $cards;
        }

        $cards[] = ['template' => '@Mail/user-card', 'order' => 35, 'data' => [
            'mailboxes' => $this->mail->mailboxes((int) $account['id']),
            'link' => $this->router->url('user.mail'),
        ]];

        return $cards;
    }
}
