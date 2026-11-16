<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * How much mail each mailbox holds (mail:usage, every 15 minutes). Mail is
 * kept in the account's home, so the home's quota already counts it; this
 * is for showing each mailbox's share and how full it is.
 */
final class MailUsage
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Measure the accounts that have mailboxes (or one account).
     *
     * @return int|null mailboxes measured, or null when the agent cannot be reached
     */
    public function refresh(?int $accountId = null): ?int
    {
        $ids = [];

        foreach ($accountId === null ? $this->accounts->withSites() : \array_filter([$this->accounts->byId($accountId)]) as $account) {
            if (\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true) && $this->mail->countMailboxes((int) $account['id']) > 0) {
                $ids[(int) $account['id']] = (string) $account['username'];
            }
        }

        $measured = 0;

        foreach ($ids as $id => $username) {
            try {
                $reply = $this->agent->call('mail.usage', ['username' => $username], timeout: 60.0);
            } catch (AgentUnavailable) {
                return null;
            }

            if (!$reply->done() || !\is_array($reply->result['mailboxes'] ?? null)) {
                continue;
            }

            $sizes = $reply->result['mailboxes'];

            foreach ($this->mail->mailboxes($id) as $box) {
                $this->mail->setUsage($id, (string) $box['address'], (int) ($sizes[$box['address']] ?? 0));
                $measured++;
            }
        }

        return $measured;
    }
}
