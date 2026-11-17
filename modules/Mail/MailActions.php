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
 * filters, spam settings, forwarders, catch-alls and where a domain's mail
 * is delivered; and the server's own mail settings (relay host, filtering)
 * and queue. The one place the User panel, the API and the CLI all go
 * through.
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
        private readonly Webmail $webmail,
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

        $outcome = $this->attempt(
            $actor,
            $request,
            $account,
            'mail.mailbox_password',
            (string) $box['address'],
            [],
            fn() => $this->mail->updateMailbox((int) $box['id'], ['hash' => Mailboxes::hash($password)]),
            fn() => $this->mail->updateMailbox((int) $box['id'], ['hash' => $before]),
        );

        // A new password ends webmail's sign-on for the mailbox too.
        if ($outcome->status !== MailOutcome::REFUSED && $outcome->status !== MailOutcome::FAILED) {
            $this->webmail->revoke($account, $box);
        }

        return $outcome;
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
     * How the account's mailboxes file spam, and its allowed and blocked
     * senders.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $input   junk, delete, allow, block
     *
     * @return array{0: MailOutcome, 1: array<string, string>} [outcome, errors by field]
     */
    public function setSpam(?Identity $actor, ?Request $request, array $account, array $input): array
    {
        [$clean, $errors] = Mailboxes::checkSpam($input);

        if ($errors !== []) {
            return [MailOutcome::refused((string) \reset($errors), (string) \array_key_first($errors)), $errors];
        }

        $before = $this->mail->spam((int) $account['id']);

        if ([$before['junk'], $before['delete'], $before['allow'], $before['block']] === [$clean['junk'], $clean['delete'], $clean['allow'], $clean['block']]) {
            return [MailOutcome::done(), []];
        }

        return [$this->attempt(
            $actor,
            $request,
            $account,
            'mail.spam',
            (string) $account['username'],
            ['junk' => $clean['junk'] / 10, 'delete' => $clean['delete'] / 10, 'allowed' => \count($clean['allow']), 'blocked' => \count($clean['block'])],
            fn() => $this->mail->setSpam((int) $account['id'], $clean),
            fn() => $this->mail->setSpam((int) $account['id'], $before),
        ), []];
    }

    /**
     * Add a filter to the end of a mailbox's filters.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $box
     * @param array<string, mixed> $input   field, test, value, action, target, stop
     *
     * @return array{0: MailOutcome, 1: array<string, string>} [outcome, errors by field]
     */
    public function addFilter(?Identity $actor, ?Request $request, array $account, array $box, array $input): array
    {
        [$clean, $errors] = Mailboxes::checkFilter($input);

        if ($errors === [] && \count($box['filters'] ?? []) >= Mailboxes::MAX_FILTERS) {
            $errors['value'] = 'Mail.error_filters_many';
        }

        if ($errors !== []) {
            return [MailOutcome::refused((string) \reset($errors), (string) \array_key_first($errors)), $errors];
        }

        $id = 0;

        return [$this->attempt(
            $actor,
            $request,
            $account,
            'mail.filter_add',
            (string) $box['address'],
            ['field' => $clean['field'], 'test' => $clean['test'], 'value' => $clean['value'], 'action' => $clean['action'], 'target' => $clean['target'] ?? ''],
            function () use (&$id, $account, $box, $clean): void {
                $id = $this->mail->addFilter((int) $account['id'], (int) $box['id'], $clean);
            },
            function () use (&$id): void {
                $this->mail->deleteFilter($id);
            },
        ), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $filter  as Mailboxes::filter() gives it
     */
    public function removeFilter(?Identity $actor, ?Request $request, array $account, array $filter): MailOutcome
    {
        $id = (int) $filter['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.filter_remove',
            (string) $filter['address'],
            ['field' => $filter['field'], 'test' => $filter['test'], 'value' => $filter['value'], 'action' => $filter['action']],
            fn() => $this->mail->deleteFilter($id),
            function () use (&$id, $account, $filter): void {
                $id = $this->mail->addFilter((int) $account['id'], (int) $filter['mailbox_id'], [
                    'field' => (string) $filter['field'], 'test' => (string) $filter['test'], 'value' => (string) $filter['value'],
                    'action' => (string) $filter['action'], 'target' => $filter['target'] ?? null, 'stop' => (bool) $filter['stop'],
                ], (int) $filter['position']);
            },
        );
    }

    /**
     * Move a filter one place earlier ("up") or later ("down").
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $box
     */
    public function moveFilter(?Identity $actor, ?Request $request, array $account, array $box, int $filterId, string $direction): MailOutcome
    {
        $ids = \array_map(static fn(array $f): int => (int) $f['id'], $box['filters'] ?? []);
        $at = \array_search($filterId, $ids, true);
        $to = \is_int($at) ? $at + ($direction === 'up' ? -1 : 1) : -1;

        if (!\is_int($at) || $to < 0 || $to >= \count($ids)) {
            return MailOutcome::done();
        }

        $order = $ids;
        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'mail.filter_move',
            (string) $box['address'],
            ['position' => $to + 1],
            fn() => $this->mail->orderFilters((int) $box['id'], \array_values($order)),
            fn() => $this->mail->orderFilters((int) $box['id'], \array_values($ids)),
        );
    }

    /**
     * The server's spam and virus filtering: Rspamd on or off, ClamAV on or
     * off (it needs Rspamd), and the score at which mail from outside is
     * refused.
     */
    public function setFiltering(?Identity $actor, ?Request $request, bool $spam, bool $virus, mixed $reject): MailOutcome
    {
        $text = \is_scalar($reject) ? \trim((string) $reject) : '';
        $tenths = \preg_match('/^\d{1,3}(\.\d)?$/', $text) === 1 ? (int) \round((float) $text * 10) : 0;

        if ($tenths < self::MIN_REJECT || $tenths > Mailboxes::MAX_SCORE) {
            return MailOutcome::refused('Mail.error_reject', 'reject');
        }

        if ($virus && !$spam) {
            return MailOutcome::refused('Mail.error_virus_needs_spam', 'virus');
        }

        $outcome = $this->server('mail.filtering', ['spam' => $spam, 'virus' => $virus, 'reject' => $tenths], 150.0);

        if ($outcome->status === MailOutcome::DONE) {
            $this->mail->setSetting('spam', $spam ? 'on' : 'off');
            $this->mail->setSetting('virus', $virus ? 'on' : 'off');
            $this->mail->setSetting('reject', (string) $tenths);
        }

        $this->audit->record('mail.filtering', $actor, $request, 'filtering', $outcome->status === MailOutcome::DONE ? AuditLog::OK : AuditLog::FAILED, ['spam' => $spam, 'virus' => $virus, 'reject' => $tenths / 10] + $this->failure($outcome));

        return $outcome;
    }

    /**
     * Deliver, remove, freeze or thaw messages in the queue, or try every
     * message now ("flush").
     *
     * @param list<string> $ids
     */
    public function queueAction(?Identity $actor, ?Request $request, string $action, array $ids): MailOutcome
    {
        if (!\in_array($action, ['deliver', 'remove', 'freeze', 'thaw', 'flush'], true)) {
            return MailOutcome::refused('Mail.error_queue_action', 'action');
        }

        $ids = \array_values(\array_unique(\array_filter(\array_map('trim', $ids), static fn(string $id): bool => \preg_match(self::MESSAGE_ID, $id) === 1)));

        if ($action !== 'flush' && $ids === []) {
            return MailOutcome::refused('Mail.error_queue_none', 'ids');
        }

        $outcome = $this->server('mail.queue_act', ['action' => $action, 'ids' => $ids], 60.0);
        $this->audit->record('mail.queue', $actor, $request, $action, $outcome->status === MailOutcome::DONE ? AuditLog::OK : AuditLog::FAILED, ['action' => $action, 'messages' => \count($ids), 'ids' => \implode(',', \array_slice($ids, 0, 20))] + $this->failure($outcome));

        return $outcome;
    }

    /** Exim's message ids (6-11-4 since 4.97, 6-6-2 before). */
    public const MESSAGE_ID = '/^[0-9A-Za-z]{6}-([0-9A-Za-z]{11}-[0-9A-Za-z]{4}|[0-9A-Za-z]{6}-[0-9A-Za-z]{2})$/';

    /** The lowest score (tenths) the server may refuse mail at. */
    public const MIN_REJECT = 50;

    /**
     * The server's relay host for outgoing mail ("" sends straight to each
     * domain's mail server), and the login to it (an empty user logs in to
     * none; an empty password with the same user keeps the one the server
     * has).
     */
    public function setSmarthost(?Identity $actor, ?Request $request, string $input, string $user = '', string $password = ''): MailOutcome
    {
        $host = \strtolower(\trim($input));
        $user = \trim($user);

        if ($host !== '' && \preg_match('/^([a-z0-9.-]+)(:(\d{1,5}))?$/', $host, $m) === 1) {
            $port = isset($m[3]) ? (int) $m[3] : 25;

            if ($port < 1 || $port > 65535 || (!Mailboxes::isDomain($m[1]) && \filter_var($m[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false)) {
                return MailOutcome::refused('Mail.error_smarthost', 'smarthost');
            }

            $host = $m[1] . ':' . $port;
        } elseif ($host !== '') {
            return MailOutcome::refused('Mail.error_smarthost', 'smarthost');
        }

        $printable = static fn(string $v): bool => \strlen($v) <= self::MAX_RELAY_LOGIN && \preg_match('/^[\x20-\x7e]*$/', $v) === 1;

        // Without a relay host there is nothing to log in to.
        if ($host === '') {
            [$user, $password] = ['', ''];
        }

        if (!$printable($user) || !$printable($password)) {
            return MailOutcome::refused('Mail.error_relay_login', 'relay_user');
        }

        $keep = $user !== '' && $password === '' && $user === $this->mail->setting('smarthost_user');

        if ($user !== '' && $password === '' && !$keep) {
            return MailOutcome::refused('Mail.error_relay_password', 'relay_password');
        }

        $args = ['smarthost' => $host, 'user' => $user, 'password' => $keep ? '' : $password, 'keep_password' => $keep];
        $outcome = $this->server('mail.settings', $args, 20.0);

        if ($outcome->status === MailOutcome::DONE) {
            $this->mail->setSetting('smarthost', $host);
            $this->mail->setSetting('smarthost_user', $host === '' ? '' : $user);
        }

        // The password is never written down, not even in the audit log.
        $this->audit->record('mail.settings', $actor, $request, 'smarthost', $outcome->status === MailOutcome::DONE ? AuditLog::OK : AuditLog::FAILED, ['smarthost' => $host, 'relay_user' => $host === '' ? '' : $user] + $this->failure($outcome));

        return $outcome;
    }

    /** The longest relay host user or password. */
    public const MAX_RELAY_LOGIN = 256;

    /**
     * A server-wide operation: done, failed (the agent said why), or refused
     * when the agent cannot be reached.
     *
     * @param array<string, mixed> $args
     */
    private function server(string $op, array $args, float $timeout): MailOutcome
    {
        try {
            $reply = $this->agent->call($op, $args, id: Protocol::newId(), timeout: $timeout);
        } catch (AgentUnavailable) {
            return MailOutcome::refused('Mail.error_unreachable');
        }

        return $reply->done() ? MailOutcome::done($reply->result) : MailOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? 'The agent gave no reason.');
    }

    /** @return array<string, mixed> what the audit log says about a failure */
    private function failure(MailOutcome $outcome): array
    {
        return $outcome->status === MailOutcome::FAILED ? ['reason' => $outcome->code, 'error' => $outcome->message] : [];
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
