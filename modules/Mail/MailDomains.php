<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Database\ConnectionManager;

/**
 * Keeps an account's mail domains in step with its domains: every domain
 * (main, addon, alias and subdomain alike) is a mail domain. A domain that
 * goes takes its mailboxes, forwarders and settings with it; its mail is
 * deleted once the server no longer has the domain set up.
 */
final class MailDomains
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Mailboxes $mail,
    ) {}

    /**
     * @param array<string, mixed> $account
     *
     * @return bool whether anything changed
     */
    public function reconcile(array $account): bool
    {
        $accountId = (int) $account['id'];
        $domains = [];

        foreach ($this->connections->connection()->table('domains')->where('account_id', $accountId)->get() as $d) {
            $domains[(int) $d['id']] = (string) $d['name'];
        }

        $changed = false;
        $have = [];

        foreach ($this->mail->domains($accountId) as $row) {
            $domainId = (int) $row['domain_id'];

            if (!isset($domains[$domainId]) || $domains[$domainId] !== $row['name']) {
                $this->mail->deleteDomain((int) $row['id']);
                $this->mail->queuePurge($accountId, (string) $row['name']);
                $changed = true;

                continue;
            }

            $have[$domainId] = true;
        }

        foreach ($domains as $domainId => $name) {
            if (!isset($have[$domainId]) && $this->connections->connection()->table('mail_domains')->where('name', $name)->first() === null) {
                $this->mail->addDomain($accountId, $domainId, $name);
                $changed = true;
            }
        }

        return $changed;
    }
}
