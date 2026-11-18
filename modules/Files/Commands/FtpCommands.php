<?php

declare(strict_types=1);

namespace App\Modules\Files\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\Ftp\FtpActions;
use App\Modules\Files\Ftp\FtpOutcome;
use App\Modules\Files\Ftp\FtpSettings;
use App\Modules\Files\Ftp\FtpSync;
use App\Modules\Files\Ftp\FtpUsers;
use App\Modules\Identity\PanelUsers;

/**
 * ftp:* from the shell, as root: the same actions as the panels and the
 * API. Passwords are read from standard input, never taken as arguments
 * (which any user on the server could see in the process list).
 */
final class FtpCommands
{
    public function __construct(
        private readonly FtpUsers $users,
        private readonly FtpActions $actions,
        private readonly FtpSync $sync,
        private readonly FtpSettings $settings,
        private readonly Accounts $accounts,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** An account's FTP logins. */
    public function list(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $output->line(\sprintf('%-48s %s', 'LOGIN', 'FOLDER'));

        foreach ($this->users->users((int) $account['id']) as $u) {
            $output->line(\sprintf('%-48s /%s', (string) $u['login'], (string) $u['folder']));
        }

        $state = $this->sync->stateOf((int) $account['id']);
        $output->line();
        $output->line('On the server: ' . ($state === null ? 'nothing yet' : $state['state'] . ($state['error'] ? ' (' . $state['error'] . ')' : '')));

        return 0;
    }

    /** A new login (name@domain); its password comes on standard input. */
    public function create(Output $output, string $username, string $login, string $folder = 'public_html'): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        if (!\str_contains($login, '@')) {
            return $this->fail($output, 'A login is a name and one of the account\'s domains, such as bob@example.com.');
        }

        [$name, $domain] = \explode('@', $login, 2);

        return $this->outcome($output, $this->actions->create($this->root(), null, $account, $name, $domain, self::readPassword(), $folder), \sprintf('FTP login %s made.', \strtolower($login)));
    }

    public function password(Output $output, string $username, string $login): int
    {
        $account = $this->account($output, $username);
        $user = $account === null ? null : $this->user($output, $account, $login);

        return $user === null ? 1 : $this->outcome($output, $this->actions->setPassword($this->root(), null, $account, $user, self::readPassword()), 'Password changed.');
    }

    public function folder(Output $output, string $username, string $login, string $folder): int
    {
        $account = $this->account($output, $username);
        $user = $account === null ? null : $this->user($output, $account, $login);

        return $user === null ? 1 : $this->outcome($output, $this->actions->setFolder($this->root(), null, $account, $user, $folder), 'Folder changed.');
    }

    public function delete(Output $output, string $username, string $login): int
    {
        $account = $this->account($output, $username);
        $user = $account === null ? null : $this->user($output, $account, $login);

        return $user === null ? 1 : $this->outcome($output, $this->actions->delete($this->root(), null, $account, $user), \sprintf('FTP login %s removed.', $user['login']));
    }

    /** The sessions open now, of every account or one. */
    public function sessions(Output $output, ?string $username = null): int
    {
        $account = null;

        if ($username !== null && $username !== '') {
            $account = $this->account($output, $username);

            if ($account === null) {
                return 1;
            }
        }

        $info = $this->actions->info($account);

        if ($info === null) {
            return $this->fail($output, $this->localization->get('Files.ftp_error_unreachable'));
        }

        $output->line(\sprintf('%-8s %-40s %-12s %-16s %-6s %8s  %s', 'PID', 'LOGIN', 'ACCOUNT', 'FROM', 'STATE', 'SECONDS', 'FILE'));

        foreach ($info['sessions'] as $s) {
            $output->line(\sprintf('%-8d %-40s %-12s %-16s %-6s %8d  %s', (int) $s['pid'], (string) $s['login'], (string) ($s['account'] ?: '-'), (string) $s['peer'], (string) $s['state'], (int) $s['seconds'], (string) $s['file']));
        }

        return 0;
    }

    public function kill(Output $output, string $pid): int
    {
        if (!\ctype_digit($pid)) {
            return $this->fail($output, 'Give the session\'s process id (ftp:sessions lists them).');
        }

        $outcome = $this->actions->kill($this->root(), null, null, (int) $pid);

        return match ($outcome->status) {
            FtpOutcome::DONE => $this->ok($output, \sprintf('Session of %s ended.', (string) ($outcome->result['login'] ?? '?'))),
            FtpOutcome::REFUSED => $this->fail($output, $this->localization->get((string) $outcome->message)),
            default => $this->fail($output, 'The server refused it: ' . $outcome->message),
        };
    }

    /** Show the server's FTP settings, or change them; --apply sends them to the server again. */
    public function settings(Output $output, ?string $tls = null, ?string $passiveFrom = null, ?string $passiveTo = null, ?string $passiveIp = null, ?string $maxClients = null, ?string $maxPerIp = null, bool $apply = false): int
    {
        $input = \array_filter([
            'tls' => $tls, 'passive_from' => $passiveFrom, 'passive_to' => $passiveTo, 'passive_ip' => $passiveIp,
            'max_clients' => $maxClients, 'max_per_ip' => $maxPerIp,
        ], static fn(?string $v): bool => $v !== null);

        if ($input !== []) {
            [$ok, $errors, $why] = $this->settings->save($this->root(), null, $input);

            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            if ($errors !== []) {
                return 1;
            }

            if (!$ok) {
                return $this->fail($output, \str_starts_with((string) $why, 'Files.') ? $this->localization->get((string) $why) : 'The server refused it: ' . $why);
            }
        } elseif ($apply) {
            [$ok, $why] = $this->settings->apply();

            if (!$ok) {
                return $this->fail($output, \str_starts_with((string) $why, 'Files.') ? $this->localization->get((string) $why) : 'The server refused it: ' . $why);
            }
        }

        $s = $this->settings->get();
        $output->line(\sprintf('%-14s %s', 'tls', $s['tls']));
        $output->line(\sprintf('%-14s %d-%d', 'passive ports', $s['passive_from'], $s['passive_to']));
        $output->line(\sprintf('%-14s %s', 'passive ip', $s['passive_ip'] === '' ? '(the address clients connect to)' : $s['passive_ip']));
        $output->line(\sprintf('%-14s %d (%d from one address)', 'clients', $s['max_clients'], $s['max_per_ip']));

        return 0;
    }

    public function settle(Output $output): int
    {
        $counts = $this->sync->settleAll();
        $output->line($counts === [] ? 'Nothing waiting.' : \implode(', ', \array_map(static fn(string $k, int $n): string => $n . ' ' . $k, \array_keys($counts), $counts)));

        return 0;
    }

    public function sync(Output $output, ?string $username = null, bool $all = false): int
    {
        if ($all) {
            $ids = \array_values(\array_unique([...$this->sync->accountIds(), ...\array_map(static fn(array $u): int => (int) $u['account_id'], $this->users->all())]));
        } elseif ($username !== null && $username !== '') {
            $account = $this->account($output, $username);

            if ($account === null) {
                return 1;
            }

            $ids = [(int) $account['id']];
        } else {
            return $this->fail($output, 'Name an account, or give --all.');
        }

        $failed = 0;

        foreach ($ids as $id) {
            $outcome = $this->sync->touch($id, true);
            $failed += $outcome->status === FtpOutcome::FAILED ? 1 : 0;

            if ($outcome->status === FtpOutcome::FAILED) {
                $output->error(\sprintf('Account %d: %s', $id, $outcome->message));
            }
        }

        return $failed > 0 ? 1 : $this->ok($output, \sprintf('Sent %d account(s).', \count($ids)));
    }

    // -------------------------------------------------------------------------

    private function outcome(Output $output, FtpOutcome $outcome, string $done): int
    {
        return match ($outcome->status) {
            FtpOutcome::REFUSED => $this->fail($output, $this->localization->get((string) $outcome->message)),
            FtpOutcome::FAILED => $this->fail($output, 'The server refused it: ' . $outcome->message),
            FtpOutcome::PENDING => $this->ok($output, 'Saved; the server has not answered yet (ftp:settle sends it).'),
            FtpOutcome::SKIPPED => $this->fail($output, 'The account is being set up or removed; try again in a moment.'),
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
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>|null
     */
    private function user(Output $output, array $account, string $login): ?array
    {
        $user = $this->users->user((int) $account['id'], \strtolower(\trim($login)));

        if ($user === null) {
            $output->error(\sprintf('%s has no FTP login %s.', $account['username'], $login));
        }

        return $user;
    }

    private static function readPassword(): string
    {
        return \rtrim((string) \fgets(\STDIN), "\r\n");
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
