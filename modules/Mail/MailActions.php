<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Changing an account's mail: mailboxes, passwords, quotas, autoresponders,
 * forwarders, catch-alls and where a domain's mail is delivered. The one
 * place the User panel, the API and the CLI all go through.
 *
 * A change is saved, then the account's whole state goes to the server
 * (MailSync); if the server refuses it, the change is undone here too. A
 * deleted mailbox's mail is deleted once the server no longer has the
 * mailbox (mail:settle finishes it when the agent cannot be reached).
 */
final class MailActions
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailSync $sync,
        private readonly Packages $packages,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain  a mail domain of the account
     */
    public function createMailbox(?Identity $actor, ?Request $request, array $account, array $domain, string $local, string $password, mixed $quota = null): MailOutcome
    {
        $package = $this->packages->byId((int) $account['package_id']);
        $limit = (int) ($package['mailboxes'] ?? 0);

        if ($limit > 0 && $this->mail->countMailboxes((int) $account['id']) >= $limit) {
            return MailOutcome::refused('Mail.error_limit', 'local');
        }

        [$local, $why] = Mailboxes::checkLocal($local);

        if ($why === null && $this->mail->mailbox((int) $account['id'], $local . '@' . $domain['name']) !== null) {
            $why = 'Mail.error_mailbox_taken';
        }

        if ($why !== null) {
            return MailOutcome::refused($why, 'local');
        }

        if (($why = Mailboxes::passwordError($password)) !== null) {
            return MailOutcome::refused($why, 'password');
        }

        // Without a quota given, the default, or the package's whole disk when that is less.
        $disk = (int) ($package['disk_mb'] ?? 0);
        $default = $disk > 0 ? \min(Mailboxes::DEFAULT_QUOTA_MB, $disk) : Mailboxes::DEFAULT_QUOTA_MB;
        [$mb, $why] = Mailboxes::checkQuota($quota === null || $quota === '' ? (string) $default : $quota, $package);

        if ($why !== null) {
            return MailOutcome::refused($why, 'quota');
        }

        $id = 0;
        $address = $local . '@' . $domain['name'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.mailbox_create',
            $address,
            ['quota_mb' => $mb],
            function () use (&$id, $account, $domain, $local, $password, $mb): void {
                $id = $this->mail->addMailbox((int) $account['id'], (int) $domain['id'], $local, Mailboxes::hash($password), $mb);
                $this->mail->cancelPurge((int) $account['id'], (string) $domain['name'], $local);
            },
            function () use (&$id): void {
                $this->mail->deleteMailbox($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $box     a mailbox of the account
     */
    public function setPassword(?Identity $actor, ?Request $request, array $account, array $box, string $password): MailOutcome
    {
        if (($why = Mailboxes::passwordError($password)) !== null) {
            return MailOutcome::refused($why, 'password');
        }

        $before = (string) $box['hash'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.mailbox_password',
            (string) $box['address'],
            [],
            fn() => $this->mail->updateMailbox((int) $box['id'], ['hash' => Mailboxes::hash($password)]),
            fn() => $this->mail->updateMailbox((int) $box['id'], ['hash' => $before]),
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $box
     */
    public function setQuota(?Identity $actor, ?Request $request, array $account, array $box, mixed $quota): MailOutcome
    {
        [$mb, $why] = Mailboxes::checkQuota($quota, $this->packages->byId((int) $account['package_id']));

        if ($why !== null) {
            return MailOutcome::refused($why, 'quota');
        }

        $before = (int) $box['quota_mb'];

        if ($before === $mb) {
            return MailOutcome::done();
        }

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.mailbox_quota',
            (string) $box['address'],
            ['quota_mb' => $mb],
            fn() => $this->mail->updateMailbox((int) $box['id'], ['quota_mb' => $mb]),
            fn() => $this->mail->updateMailbox((int) $box['id'], ['quota_mb' => $before]),
        );
    }

    /**
     * Delete a mailbox, and (once the server no longer has it) its mail.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $box
     */
    public function deleteMailbox(?Identity $actor, ?Request $request, array $account, array $box): MailOutcome
    {
        $id = (int) $box['id'];
        $outcome = $this->attempt(
            $actor,
            $request,
            $account,
            'mail.mailbox_delete',
            (string) $box['address'],
            [],
            function () use ($id, $account, $box): void {
                $this->mail->deleteMailbox($id);
                $this->mail->queuePurge((int) $account['id'], (string) $box['domain'], (string) $box['local']);
            },
            function () use (&$id, $account, $box): void {
                $this->mail->cancelPurge((int) $account['id'], (string) $box['domain'], (string) $box['local']);
                $id = $this->mail->restoreMailbox($box);
            },
        );

        if ($outcome->status === MailOutcome::DONE) {
            $this->sync->purge((int) $account['id']);
        }

        return $outcome;
    }

    /**
     * Turn a mailbox's autoresponder on (or change it), or off with null.
     *
     * @param array<string, mixed>      $account
     * @param array<string, mixed>      $box
     * @param array<string, mixed>|null $input subject, body, from_name, days, start, end
     *
     * @return array{0: MailOutcome, 1: array<string, string>} [outcome, errors by field]
     */
    public function setResponder(?Identity $actor, ?Request $request, array $account, array $box, ?array $input): array
    {
        $clean = null;

        if ($input !== null) {
            [$clean, $errors] = Mailboxes::checkResponder($input);

            if ($errors !== []) {
                return [MailOutcome::refused((string) \reset($errors), (string) \array_key_first($errors)), $errors];
            }
        }

        $before = \is_array($box['responder'] ?? null) ? $box['responder'] : null;

        if ($before === null && $clean === null) {
            return [MailOutcome::done(), []];
        }

        return [$this->attempt(
            $actor,
            $request,
            $account,
            $clean === null ? 'mail.responder_off' : 'mail.responder_on',
            (string) $box['address'],
            $clean === null ? [] : ['days' => $clean['days'], 'start' => $clean['starts_on'] ?? '', 'end' => $clean['ends_on'] ?? ''],
            fn() => $this->mail->setResponder((int) $box['id'], $clean),
            fn() => $this->mail->setResponder((int) $box['id'], $before),
        ), []];
    }

    /**
     * Forward mail for an address of the account to another address.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     */
    public function addForwarder(?Identity $actor, ?Request $request, array $account, array $domain, string $local, string $destination): MailOutcome
    {
        [$local, $why] = Mailboxes::checkLocal($local);

        if ($why !== null) {
            return MailOutcome::refused($why, 'local');
        }

        [$destination, $why] = Mailboxes::checkAddress($destination);
        $address = $local . '@' . $domain['name'];

        if ($why === null && \strtolower($destination) === $address) {
            $why = 'Mail.error_forward_self';
        } elseif ($why === null && $this->mail->hasForwarder((int) $domain['id'], $local, $destination)) {
            $why = 'Mail.error_forward_taken';
        } elseif ($why === null && $this->mail->countTargets((int) $domain['id'], $local) >= Mailboxes::MAX_TARGETS) {
            $why = 'Mail.error_forward_many';
        }

        if ($why !== null) {
            return MailOutcome::refused($why, 'destination');
        }

        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.forwarder_add',
            $address,
            ['to' => $destination],
            function () use (&$id, $account, $domain, $local, $destination): void {
                $id = $this->mail->addForwarder((int) $account['id'], (int) $domain['id'], $local, $destination);
            },
            function () use (&$id): void {
                $this->mail->deleteForwarder($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $forwarder
     */
    public function removeForwarder(?Identity $actor, ?Request $request, array $account, array $forwarder): MailOutcome
    {
        $id = (int) $forwarder['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.forwarder_remove',
            (string) $forwarder['address'],
            ['to' => $forwarder['destination']],
            fn() => $this->mail->deleteForwarder($id),
            function () use (&$id, $account, $forwarder): void {
                $id = $this->mail->addForwarder((int) $account['id'], (int) $forwarder['mail_domain_id'], (string) $forwarder['local'], (string) $forwarder['destination']);
            },
        );
    }

    /**
     * A domain's catch-all ("refuse", "discard" or "forward" to an address)
     * and whether its mail is delivered here.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     */
    public function setDomain(?Identity $actor, ?Request $request, array $account, array $domain, ?string $catchall, string $forwardTo = '', ?bool $local = null): MailOutcome
    {
        $changes = [];

        if ($catchall !== null) {
            [$value, $why] = Mailboxes::checkCatchall($catchall, $forwardTo);

            if ($why !== null) {
                return MailOutcome::refused($why, 'catchall');
            }

            if ($value !== ($domain['catchall'] ?? null)) {
                $changes['catchall'] = $value;
            }
        }

        if ($local !== null && $local !== (bool) $domain['local']) {
            $changes['local'] = $local;
        }

        if ($changes === []) {
            return MailOutcome::done();
        }

        $before = ['catchall' => $domain['catchall'] ?? null, 'local' => (bool) $domain['local']];
        $details = [];

        if (\array_key_exists('catchall', $changes)) {
            $details['catchall'] = $changes['catchall'] ?? 'refuse';
        }

        if (isset($changes['local'])) {
            $details['local'] = $changes['local'];
        }

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.domain_settings',
            (string) $domain['name'],
            $details,
            fn() => $this->mail->updateDomain((int) $domain['id'], $changes),
            fn() => $this->mail->updateDomain((int) $domain['id'], $before),
        );
    }

    /**
     * The server's relay host for outgoing mail ("" sends straight to each
     * domain's mail server).
     */
    public function setSmarthost(?Identity $actor, ?Request $request, string $input): MailOutcome
    {
        $host = \strtolower(\trim($input));

        if ($host !== '' && \preg_match('/^([a-z0-9.-]+)(:(\d{1,5}))?$/', $host, $m) === 1) {
            $port = isset($m[3]) ? (int) $m[3] : 25;

            if ($port < 1 || $port > 65535 || (!Mailboxes::isDomain($m[1]) && \filter_var($m[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false)) {
                return MailOutcome::refused('Mail.error_smarthost', 'smarthost');
            }

            $host = $m[1] . ':' . $port;
        } elseif ($host !== '') {
            return MailOutcome::refused('Mail.error_smarthost', 'smarthost');
        }

        try {
            $reply = $this->agent->call('mail.settings', ['smarthost' => $host], id: Protocol::newId(), timeout: 20.0);
        } catch (AgentUnavailable) {
            return MailOutcome::refused('Mail.error_unreachable');
        }

        $outcome = $reply->done() ? MailOutcome::done($reply->result) : MailOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? 'The agent gave no reason.');

        if ($outcome->status === MailOutcome::DONE) {
            $this->mail->setSetting('smarthost', $host);
        }

        $this->audit->record('mail.settings', $actor, $request, 'smarthost', $outcome->status === MailOutcome::DONE ? AuditLog::OK : AuditLog::FAILED, ['smarthost' => $host] + ($outcome->status === MailOutcome::FAILED ? ['reason' => $outcome->code, 'error' => $outcome->message] : []));

        return $outcome;
    }

    // -------------------------------------------------------------------------

    /**
     * Save, send, and undo both if the server refuses; audit either way.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function attempt(?Identity $actor, ?Request $request, array $account, string $action, string $subject, array $details, \Closure $do, \Closure $undo): MailOutcome
    {
        $do();
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === MailOutcome::FAILED) {
            $undo();
            $this->sync->touch((int) $account['id']);
        }

        if ($outcome->status === MailOutcome::REFUSED) {
            return $outcome;
        }

        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === MailOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === MailOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $subject, $outcome->status === MailOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }
}
