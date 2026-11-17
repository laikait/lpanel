<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Opening webmail (Roundcube) as one of the account's mailboxes, with no
 * password.
 *
 * The panel never has a mailbox's password. The agent gives a sign-on
 * password for the mailbox instead (mail.sso: random, good for a few hours,
 * accepted by Dovecot beside the mailbox's own, and the same one while it is
 * still good, so an open webmail session keeps working). The panel writes it
 * into a one-time ticket file and sends the browser to Roundcube, whose
 * lpanel_sso plugin reads the ticket, deletes it and signs in with it.
 *
 * Tickets live a minute, in a folder only the panel and Roundcube's own PHP
 * pool (a separate system user) can read; the password is never stored by
 * the panel. mail:settle asks the server to forget expired passwords.
 */
final class Webmail
{
    /** A ticket is good for this long. */
    public const TICKET_SECONDS = 60;

    /** A sign-on password lasts this long after the last sign-on. */
    public const HOURS = 8;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly MailHosts $hosts,
    ) {}

    /**
     * Make a ticket for the mailbox and return where to send the browser.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $mailbox a row of Mailboxes::mailboxes()
     *
     * @return array{0: ?string, 1: ?string} [url, error key]
     */
    public function signOn(?Identity $actor, ?Request $request, array $account, array $mailbox, bool $absolute = false): array
    {
        $address = (string) $mailbox['address'];

        if ($account['status'] !== Accounts::ACTIVE) {
            return [null, 'Mail.error_webmail_suspended'];
        }

        $dir = $this->ticketDir();

        if (!\is_dir($dir) || !\is_writable($dir)) {
            return [null, 'Mail.error_webmail_missing'];
        }

        try {
            $reply = $this->agent->call('mail.sso', ['username' => (string) $account['username'], 'address' => $address, 'hours' => self::HOURS], id: Protocol::newId(), timeout: 20.0);
        } catch (AgentUnavailable) {
            return [null, 'Mail.error_unreachable'];
        }

        if (!$reply->done() || !\is_string($reply->result['user'] ?? null) || !\is_string($reply->result['password'] ?? null)) {
            $this->audit->record('mail.webmail', $actor, $request, $address, AuditLog::FAILED, ['reason' => $reply->errorCode, 'error' => $reply->errorMessage]);

            return [null, $reply->errorCode === 'mail_no_mailbox' ? 'Mail.error_webmail_pending' : 'Mail.error_webmail_failed'];
        }

        $token = \bin2hex(\random_bytes(32));
        $ticket = \json_encode([
            'user' => $reply->result['user'],
            'password' => $reply->result['password'],
            'expires' => \time() + self::TICKET_SECONDS,
        ], \JSON_THROW_ON_ERROR);

        // Readable by Roundcube's pool through the folder's group, and by no
        // one else.
        $old = \umask(0o137);

        try {
            if (@\file_put_contents($dir . '/' . $token, $ticket, \LOCK_EX) === false) {
                return [null, 'Mail.error_webmail_missing'];
            }
        } finally {
            \umask($old);
        }

        @\chmod($dir . '/' . $token, 0o640);
        $until = (int) ($reply->result['expires'] ?? (\time() + self::HOURS * 3600));
        $this->connections->connection()->table('mail_boxes')->where('id', (int) $mailbox['id'])->update(['webmail_until' => \gmdate('Y-m-d H:i:s', $until)]);
        $this->audit->record('mail.webmail', $actor, $request, $address, AuditLog::OK, []);

        return [$this->url($absolute) . '?_task=login&_lpanel=' . $token, null];
    }

    /**
     * Forget sign-on passwords past their time on the server, and tickets
     * nobody used.
     *
     * @return int mailboxes whose sign-on ended
     */
    public function expire(): int
    {
        $now = \gmdate('Y-m-d H:i:s');
        $due = $this->connections->connection()->table('mail_boxes')->where('webmail_until', '<', $now)->get();
        $ended = 0;

        if ($due !== []) {
            try {
                $reply = $this->agent->call('mail.sso_expire', [], id: Protocol::newId(), timeout: 30.0);
            } catch (AgentUnavailable) {
                $reply = null;
            }

            if ($reply !== null && ($reply->done() || $reply->errorCode === 'mail_off')) {
                foreach ($due as $row) {
                    $this->connections->connection()->table('mail_boxes')->where('id', (int) $row['id'])->where('webmail_until', '<', $now)->update(['webmail_until' => null]);
                    $ended++;
                }
            }
        }

        foreach (\glob($this->ticketDir() . '/*') ?: [] as $file) {
            if (\preg_match('/^[0-9a-f]{64}$/', \basename($file)) === 1 && (int) @\filemtime($file) < \time() - 5 * self::TICKET_SECONDS) {
                @\unlink($file);
            }
        }

        return $ended;
    }

    /**
     * End a mailbox's sign-on at once (its password changed).
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $mailbox
     */
    public function revoke(array $account, array $mailbox): void
    {
        if (($mailbox['webmail_until'] ?? null) === null) {
            return;
        }

        try {
            $reply = $this->agent->call('mail.sso_revoke', ['username' => (string) $account['username'], 'address' => (string) $mailbox['address']], id: Protocol::newId(), timeout: 20.0);
        } catch (AgentUnavailable) {
            return;
        }

        if ($reply->done()) {
            $this->connections->connection()->table('mail_boxes')->where('id', (int) $mailbox['id'])->update(['webmail_until' => null]);
        }
    }

    /** Where webmail is: on the User panel's own port, or in full. */
    public function url(bool $absolute = false): string
    {
        $path = (string) $this->config->get('Mail.webmail_url', '/webmail/');

        if (!$absolute) {
            return $path;
        }

        return $this->hosts->webmailUrl() ?? $path;
    }

    public function ticketDir(): string
    {
        return \rtrim((string) $this->config->get('Mail.webmail_tickets', '/var/lib/lpanel-webmail/tickets'), '/');
    }
}
