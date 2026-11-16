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
use App\Modules\Mail\MailDomains;
use App\Modules\Mail\MailOutcome;
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

    public function settings(Output $output, ?string $smarthost = null): int
    {
        if ($smarthost !== null) {
            $outcome = $this->actions->setSmarthost($this->root(), null, $smarthost === 'none' ? '' : $smarthost);

            if ($outcome->status !== MailOutcome::DONE) {
                return $this->outcome($output, $outcome, '');
            }
        }

        $output->pairs(['Relay host' => $this->mail->setting('smarthost') ?: 'none (straight to each domain\'s mail server)']);

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
        $output->pairs([
            'Mail' => ($info['enabled'] ?? false) ? 'on' : 'off',
            'Exim' => (($exim['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($exim['version'] ?? ''),
            'Dovecot' => (($dovecot['running'] ?? false) ? 'running' : 'stopped') . ' ' . ($dovecot['version'] ?? ''),
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
