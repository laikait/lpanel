<?php

declare(strict_types=1);

namespace App\Modules\Mail\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Identity\PanelUsers;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\Http\MailToolPages;
use App\Modules\Mail\MailDomains;
use App\Modules\Mail\MailOutcome;
use App\Modules\Mail\MailQueue;
use App\Modules\Mail\MailSync;
use App\Modules\Mail\MailUsage;

/**
 * mail:* from the shell, as root: the same actions as the panels and the
 * API. Passwords and autoresponder messages are read from standard input,
 * never taken as arguments (which any user on the server could see in the
 * process list).
 */
final class MailCommands
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailActions $actions,
        private readonly MailSync $sync,
        private readonly MailUsage $usage,
        private readonly MailDomains $domains,
        private readonly MailQueue $queue,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    /** An account's mail domains, mailboxes and forwarders. */
    public function list(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $id = (int) $account['id'];
        $output->line(\sprintf('%-36s %-8s %s', 'DOMAIN', 'LOCAL', 'CATCH-ALL'));

        foreach ($this->mail->domains($id) as $d) {
            $output->line(\sprintf('%-36s %-8s %s', (string) $d['name'], (bool) $d['local'] ? 'yes' : 'no', match (true) {
                ($d['catchall'] ?? null) === null => 'refuse',
                $d['catchall'] === Mailboxes::DISCARD => 'discard',
                default => '-> ' . $d['catchall'],
            }));
        }

        $output->line();
        $output->line(\sprintf('%-40s %10s %10s %s', 'MAILBOX', 'USED', 'QUOTA', 'AUTORESPONDER'));

        foreach ($this->mail->mailboxes($id) as $b) {
            $output->line(\sprintf('%-40s %10s %10s %s', (string) $b['address'], self::bytes((int) $b['used_bytes']), (int) $b['quota_mb'] > 0 ? $b['quota_mb'] . ' MB' : 'none', $b['responder'] === null ? '-' : 'on'));
        }

        $output->line();
        $output->line(\sprintf('%-40s %s', 'FORWARDER', 'TO'));

        foreach ($this->mail->forwarders($id) as $f) {
            $output->line(\sprintf('%-40s %s', (string) $f['address'], (string) $f['destination']));
        }

        $state = $this->sync->stateOf($id);
        $output->line();
        $output->line('On the server: ' . ($state === null ? 'nothing yet' : $state['state'] . ($state['error'] ? ' (' . $state['error'] . ')' : '')));

        return 0;
    }

    /** A new mailbox; its password comes on standard input. */
    public function create(Output $output, string $username, string $address, ?string $quota = null): int
    {
        [$account, $domain, $local] = $this->address($output, $username, $address);

        if ($domain === null) {
            return 1;
        }

        return $this->outcome($output, $this->actions->createMailbox($this->root(), null, $account, $domain, $local, self::stdinLine(), $quota), \sprintf('Mailbox %s created.', \strtolower($address)));
    }

    public function password(Output $output, string $username, string $address): int
    {
        [$account, $box] = $this->mailbox($output, $username, $address);

        return $box === null ? 1 : $this->outcome($output, $this->actions->setPassword($this->root(), null, $account, $box, self::stdinLine()), 'Password changed.');
    }

    public function quota(Output $output, string $username, string $address, string $mb): int
    {
        [$account, $box] = $this->mailbox($output, $username, $address);

        return $box === null ? 1 : $this->outcome($output, $this->actions->setQuota($this->root(), null, $account, $box, $mb), 'Quota changed.');
    }

    public function delete(Output $output, string $username, string $address, bool $yes = false): int
    {
        [$account, $box] = $this->mailbox($output, $username, $address);

        if ($box === null) {
            return 1;
        }

        if (!$yes) {
            $output->error(\sprintf('This deletes %s and all its mail. Say --yes to go on.', $box['address']));

            return 1;
        }

        return $this->outcome($output, $this->actions->deleteMailbox($this->root(), null, $account, $box), \sprintf('Mailbox %s deleted.', $box['address']));
    }

    public function forward(Output $output, string $username, string $address, string $to): int
    {
        [$account, $domain, $local] = $this->address($output, $username, $address);

        return $domain === null ? 1 : $this->outcome($output, $this->actions->addForwarder($this->root(), null, $account, $domain, $local, $to), 'Forwarder added.');
    }

    public function unforward(Output $output, string $username, string $address, string $to): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        foreach ($this->mail->forwarders((int) $account['id']) as $f) {
            if ($f['address'] === \strtolower($address) && \strtolower((string) $f['destination']) === \strtolower($to)) {
                return $this->outcome($output, $this->actions->removeForwarder($this->root(), null, $account, $f), 'Forwarder removed.');
            }
        }

        $output->error(\sprintf('%s does not forward to %s.', $address, $to));

        return 1;
    }

    /** Turn an autoresponder on (its message from standard input), or off. */
    public function responder(Output $output, string $username, string $address, ?string $subject = null, ?string $fromName = null, ?string $days = null, ?string $start = null, ?string $end = null, bool $off = false): int
    {
        [$account, $box] = $this->mailbox($output, $username, $address);

        if ($box === null) {
            return 1;
        }

        if ($off) {
            [$outcome] = $this->actions->setResponder($this->root(), null, $account, $box, null);

            return $this->outcome($output, $outcome, 'Autoresponder off.');
        }

        $body = (string) \stream_get_contents(\STDIN);
        [$outcome, $errors] = $this->actions->setResponder($this->root(), null, $account, $box, [
            'subject' => $subject ?? '', 'body' => $body, 'from_name' => $fromName ?? '', 'days' => $days ?? '1', 'start' => $start ?? '', 'end' => $end ?? '',
        ]);

        if ($errors !== []) {
            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            return 1;
        }

        return $this->outcome($output, $outcome, 'Autoresponder on.');
    }

    public function domain(Output $output, string $username, string $domain, ?string $catchall = null, ?string $local = null): int
    {
        $account = $this->account($output, $username);
        $row = $account === null ? null : $this->mail->domain((int) $account['id'], $domain);

        if ($row === null) {
            $output->error(\sprintf('%s has no mail domain %s.', $username, $domain));

            return 1;
        }

        $action = null;
        $to = '';

        if ($catchall !== null) {
            [$action, $to] = \in_array($catchall, ['refuse', 'discard'], true) ? [$catchall, ''] : ['forward', $catchall];
        }

        if ($local !== null && !\in_array($local, ['yes', 'no'], true)) {
            $output->error('--local is yes or no.');

            return 1;
        }

        return $this->outcome($output, $this->actions->setDomain($this->root(), null, $account, $row, $action, $to, $local === null ? null : $local === 'yes'), 'Saved.');
    }

    public function settings(Output $output, ?string $smarthost = null, ?string $relayUser = null): int
    {
        if ($smarthost !== null || $relayUser !== null) {
            $host = $smarthost === null ? $this->mail->setting('smarthost') : ($smarthost === 'none' ? '' : $smarthost);
            $user = $relayUser === null ? $this->mail->setting('smarthost_user') : ($relayUser === 'none' ? '' : $relayUser);
            // A new user's password comes from standard input; the same user keeps theirs.
            $password = $relayUser !== null && $user !== '' ? self::stdinLine() : '';
            $outcome = $this->actions->setSmarthost($this->root(), null, $host, $user, $password);

            if ($outcome->status !== MailOutcome::DONE) {
                return $this->outcome($output, $outcome, '');
            }
        }

        $user = $this->mail->setting('smarthost_user');
        $output->pairs([
            'Relay host' => $this->mail->setting('smarthost') ?: 'none (straight to each domain\'s mail server)',
            'Login' => $user === '' ? 'none' : $user . ' (over TLS only)',
        ]);

        return 0;
    }

    public function filtering(Output $output, ?string $spam = null, ?string $virus = null, ?string $reject = null): int
    {
        $current = [
            'spam' => $this->mail->setting('spam', 'off') === 'on',
            'virus' => $this->mail->setting('virus', 'off') === 'on',
            'reject' => MailToolPages::score((int) $this->mail->setting('reject', '150')),
        ];

        if ($spam !== null || $virus !== null || $reject !== null) {
            foreach (['spam' => $spam, 'virus' => $virus] as $name => $value) {
                if ($value !== null && !\in_array($value, ['on', 'off'], true)) {
                    return $this->fail($output, \sprintf('--%s must be on or off.', $name));
                }
            }

            $outcome = $this->actions->setFiltering($this->root(), null, $spam === null ? $current['spam'] : $spam === 'on', $virus === null ? $current['virus'] : $virus === 'on', $reject ?? $current['reject']);

            if ($outcome->status !== MailOutcome::DONE) {
                return $this->outcome($output, $outcome, '');
            }
        }

        $output->pairs([
            'Spam filtering' => $this->mail->setting('spam', 'off') === 'on' ? 'on (Rspamd)' : 'off',
            'Virus scanning' => $this->mail->setting('virus', 'off') === 'on' ? 'on (ClamAV)' : 'off',
            'Refused from score' => MailToolPages::score((int) $this->mail->setting('reject', '150')),
        ]);

        return 0;
    }

    public function spam(Output $output, string $username, ?string $junk = null, ?string $delete = null, ?string $allow = null, ?string $block = null): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $now = $this->mail->spam((int) $account['id']);

        if ($junk !== null || $delete !== null || $allow !== null || $block !== null) {
            $score = static fn(?string $v, int $current): string => $v === null ? ($current > 0 ? MailToolPages::score($current) : '') : ($v === 'off' ? '' : $v);
            $list = static fn(?string $v, array $current): string => $v === null ? \implode(',', $current) : ($v === 'none' ? '' : $v);
            [$outcome, $errors] = $this->actions->setSpam($this->root(), null, $account, [
                'junk' => $score($junk, $now['junk']), 'delete' => $score($delete, $now['delete']),
                'allow' => $list($allow, $now['allow']), 'block' => $list($block, $now['block']),
            ]);

            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            if ($errors !== [] || $outcome->status !== MailOutcome::DONE) {
                return $errors !== [] ? 2 : $this->outcome($output, $outcome, '');
            }

            $now = $this->mail->spam((int) $account['id']);
        }

        $output->pairs([
            'Junk folder from' => $now['junk'] > 0 ? MailToolPages::score($now['junk']) : 'off',
            'Deleted from' => $now['delete'] > 0 ? MailToolPages::score($now['delete']) : 'off',
            'Allowed senders' => $now['allow'] === [] ? 'none' : \implode(', ', $now['allow']),
            'Blocked senders' => $now['block'] === [] ? 'none' : \implode(', ', $now['block']),
            'Server filtering' => $this->mail->setting('spam', 'off') === 'on' ? 'on' : 'off (nothing is scored)',
        ]);

        return 0;
    }

    public function filter(Output $output, string $username, string $address, ?string $field = null, ?string $test = null, ?string $value = null, ?string $action = null, ?string $target = null, ?string $remove = null, bool $stop = false): int
    {
        [$account, $box] = $this->mailbox($output, $username, $address);

        if ($box === null) {
            return 1;
        }

        if ($remove !== null) {
            $filter = \ctype_digit($remove) ? $this->mail->filter((int) $account['id'], (int) $remove) : null;

            if ($filter === null || (int) $filter['mailbox_id'] !== (int) $box['id']) {
                return $this->fail($output, \sprintf('%s has no filter %s.', $address, $remove));
            }

            return $this->outcome($output, $this->actions->removeFilter($this->root(), null, $account, $filter), 'Filter removed.');
        }

        if ($field !== null || $value !== null || $action !== null) {
            [$outcome, $errors] = $this->actions->addFilter($this->root(), null, $account, $box, [
                'field' => $field ?? '', 'test' => $test ?? 'contains', 'value' => $value ?? '', 'action' => $action ?? '', 'target' => $target ?? '', 'stop' => $stop,
            ]);

            foreach ($errors as $f => $key) {
                $output->error($f . ': ' . $this->localization->get($key));
            }

            return $errors !== [] ? 2 : $this->outcome($output, $outcome, 'Filter added.');
        }

        $output->line(\sprintf('%-6s %-8s %-9s %-30s %s', 'ID', 'FIELD', 'TEST', 'VALUE', 'ACTION'));

        foreach ($box['filters'] as $f) {
            $output->line(\sprintf('%-6d %-8s %-9s %-30s %s%s', (int) $f['id'], $f['field'], $f['test'], '"' . $f['value'] . '"', $f['action'] . ($f['target'] !== null ? ' ' . $f['target'] : ''), (bool) $f['stop'] ? ' (stop)' : ''));
        }

        return 0;
    }

    public function queue(Output $output, ?string $action = null, ?string $ids = null, ?string $search = null, bool $yes = false): int
    {
        $action ??= 'list';
        $list = $ids === null ? [] : \array_values(\array_filter(\array_map('trim', \explode(',', $ids))));

        if ($action === 'list') {
            [$q, $error] = $this->queue->list($search ?? '', 1000);

            if ($q === null) {
                return $this->fail($output, $this->localization->get((string) $error));
            }

            $output->line(\sprintf('%-24s %6s %8s %-32s %s', 'ID', 'AGE', 'SIZE', 'FROM', 'TO'));

            foreach (\is_array($q['messages'] ?? null) ? $q['messages'] : [] as $m) {
                $output->line(\sprintf('%-24s %6s %8s %-32s %s%s', $m['id'], self::age((int) $m['age']), self::bytes((int) $m['size']), $m['sender'] === '' ? '<>' : $m['sender'], \implode(', ', $m['recipients']), $m['frozen'] ? '  *frozen*' : ''));
            }

            $output->line(\sprintf('%d in the queue, %d frozen.', (int) ($q['total'] ?? 0), (int) ($q['frozen'] ?? 0)));

            return 0;
        }

        if ($action === 'show') {
            [$m, $error] = $this->queue->view($list[0] ?? '');

            if ($m === null) {
                return $this->fail($output, $this->localization->get((string) $error));
            }

            $output->line((string) $m['message']);
            $output->line('--- log ---');
            $output->line((string) $m['log']);

            return 0;
        }

        if ($action === 'remove' && !$yes) {
            return $this->fail($output, \sprintf('This removes %d message(s) from the queue; they will not be delivered. Say --yes to go on.', \count($list)));
        }

        return $this->outcome($output, $this->actions->queueAction($this->root(), null, $action, $list), match ($action) {
            'deliver' => 'Delivery started.',
            'flush' => 'Every message is being tried now.',
            default => \sprintf('%s: %d message(s).', \ucfirst($action), \count($list)),
        });
    }

    public function track(Output $output, ?string $account = null, ?string $search = null, ?string $status = null, ?string $days = null): int
    {
        if ($account !== null && $this->account($output, $account) === null) {
            return 1;
        }

        [$r, $error] = $this->queue->track($account, $search ?? '', $status ?? '', (int) ($days ?? '7'), 1000);

        if ($r === null) {
            return $this->fail($output, $this->localization->get((string) $error));
        }

        $output->line(\sprintf('%-19s %-10s %-32s %-32s %s', 'TIME', 'STATUS', 'FROM', 'TO', 'DETAIL'));

        foreach (\is_array($r['deliveries'] ?? null) ? $r['deliveries'] : [] as $d) {
            $output->line(\sprintf('%-19s %-10s %-32s %-32s %s', $d['time'], $d['status'], $d['from'] === '' ? '<>' : $d['from'], $d['to'], $d['detail']));
        }

        if ((bool) ($r['more'] ?? false)) {
            $output->line('(more; narrow it with --search, --status or --days)');
        }

        return 0;
    }

    public function info(Output $output): int
    {
        try {
            $reply = $this->agent->call('mail.info', [], timeout: 15.0);
        } catch (AgentUnavailable $e) {
            $output->error('The agent cannot be reached: ' . $e->getMessage());

            return 1;
        }

        $info = $reply->done() ? $reply->result : [];
        $exim = \is_array($info['exim'] ?? null) ? $info['exim'] : [];
        $dovecot = \is_array($info['dovecot'] ?? null) ? $info['dovecot'] : [];
        $rspamd = \is_array($info['rspamd'] ?? null) ? $info['rspamd'] : [];
        $clam = \is_array($info['clamav'] ?? null) ? $info['clamav'] : [];
        $output->pairs([
            'Mail' => ($info['enabled'] ?? false) ? 'on' : 'off',
            'Exim' => (($exim['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($exim['version'] ?? ''),
            'Dovecot' => (($dovecot['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($dovecot['version'] ?? ''),
            'Rspamd' => (($rspamd['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($rspamd['version'] ?? ''),
            'ClamAV' => (($clam['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($clam['version'] ?? '') . (($clam['ready'] ?? false) ? '' : ' (no signatures yet)'),
            'Queue' => (string) ($info['queue'] ?? 0),
            'Mail domains' => (string) ($info['domains'] ?? 0),
            'Relay host' => $this->mail->setting('smarthost') ?: 'none',
        ]);

        return 0;
    }

    public function usage(Output $output): int
    {
        $n = $this->usage->refresh();

        if ($n === null) {
            $output->error('The agent cannot be reached.');

            return 1;
        }

        $output->success(\sprintf('%d mailboxes measured.', $n));

        return 0;
    }

    public function settle(Output $output): int
    {
        $counts = $this->sync->settleAll();
        $output->line($counts === [] ? 'Nothing to send.' : \implode(', ', \array_map(static fn(string $k, int $n): string => $n . ' ' . $k, \array_keys($counts), $counts)));

        return 0;
    }

    public function sync(Output $output, ?string $username = null, bool $all = false): int
    {
        if ($username === null && !$all) {
            $output->error('Name an account, or say --all.');

            return 1;
        }

        $targets = $all ? $this->accounts->withSites() : \array_filter([$this->account($output, (string) $username)]);
        $failed = 0;

        foreach ($targets as $account) {
            // Accounts made before mail came get their mail domains first.
            $this->domains->reconcile($account);
            $outcome = $this->sync->touch((int) $account['id'], force: true);
            $this->sync->publish((int) $account['id']);
            $output->line(\sprintf('%-16s %s%s', (string) $account['username'], $outcome->status, $outcome->message !== null ? ' (' . $outcome->message . ')' : ''));
            $failed += $outcome->status === MailOutcome::FAILED ? 1 : 0;
        }

        return ($targets === [] && !$all) || $failed > 0 ? 1 : 0;
    }

    // -------------------------------------------------------------------------

    private function outcome(Output $output, MailOutcome $outcome, string $done): int
    {
        return match ($outcome->status) {
            MailOutcome::REFUSED => $this->fail($output, $this->localization->get((string) $outcome->message)),
            MailOutcome::FAILED => $this->fail($output, 'The server refused it: ' . $outcome->message),
            MailOutcome::PENDING => $this->ok($output, 'Saved; the server has not answered yet (mail:settle sends it).'),
            MailOutcome::SKIPPED => $this->fail($output, 'The account is being set up or removed; try again in a moment.'),
            default => $this->ok($output, $done),
        };
    }

    private function ok(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function fail(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    /** @return array<string, mixed>|null */
    private function account(Output $output, string $username): ?array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $account;
    }

    /**
     * The account, the mail domain of an address and its local part.
     *
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: string}
     */
    private function address(Output $output, string $username, string $address): array
    {
        $account = $this->account($output, $username);
        $at = \strrpos($address, '@');

        if ($account === null || $at === false) {
            if ($account !== null) {
                $output->error(\sprintf('%s is not an address.', $address));
            }

            return [$account ?? [], null, ''];
        }

        $domain = $this->mail->domain((int) $account['id'], \strtolower(\substr($address, $at + 1)));

        if ($domain === null) {
            $output->error(\sprintf('%s has no mail domain %s.', $username, \substr($address, $at + 1)));
        }

        return [$account, $domain, \substr($address, 0, $at)];
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} */
    private function mailbox(Output $output, string $username, string $address): array
    {
        $account = $this->account($output, $username);
        $box = $account === null ? null : $this->mail->mailbox((int) $account['id'], \strtolower($address));

        if ($account !== null && $box === null) {
            $output->error(\sprintf('%s has no mailbox %s.', $username, $address));
        }

        return [$account ?? [], $box];
    }

    private static function age(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => \intdiv($seconds, 60) . 'm',
            $seconds < 172800 => \intdiv($seconds, 3600) . 'h',
            default => \intdiv($seconds, 86400) . 'd',
        };
    }

    private static function stdinLine(): string
    {
        return \rtrim((string) \fgets(\STDIN), "\r\n");
    }

    private static function bytes(int $bytes): string
    {
        $n = (float) $bytes;

        foreach (['KB', 'MB', 'GB'] as $unit) {
            $n /= 1024;

            if ($n < 1024) {
                return $bytes < 1024 ? $bytes . ' B' : \sprintf('%.1f %s', $n, $unit);
            }
        }

        return \sprintf('%.1f TB', $n / 1024);
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
