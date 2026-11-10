<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\AccountView;
use App\Modules\Accounts\Http\AccountPages;
use App\Modules\Accounts\Outcome;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\PanelUsers;

/**
 * accounts:* — the same actions as the Admin panel, as root, from the shell.
 */
final class AccountCommands
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountRules $rules,
        private readonly AccountView $view,
        private readonly Provisioner $provisioner,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function list(Output $output, ?string $status = null): int
    {
        $output->line(\sprintf('%-16s %-32s %-14s %-12s %s', 'USERNAME', 'DOMAIN', 'PACKAGE', 'STATUS', 'DISK'));

        foreach ($this->accounts->visibleTo($this->root(), '', $status ?? '') as $row) {
            $row = $this->view->enrich($row);
            $disk = $row['disk_used_bytes'] === null ? '-' : \sprintf('%.1f MB', $row['disk_used_bytes'] / 1048576);
            $output->line(\sprintf('%-16s %-32s %-14s %-12s %s', $row['username'], $row['domain'], $row['package_name'], $row['status'], $disk));
        }

        return 0;
    }

    /** The owner's password is read from standard input. */
    public function create(Output $output, string $username, string $domain, string $package, ?string $owner = null, ?string $email = null, ?string $name = null): int
    {
        $password = \rtrim((string) \fgets(\STDIN), "\r\n");
        [$clean, $errors] = $this->rules->validateNew([
            'username' => $username, 'domain' => $domain, 'package' => $package, 'owner' => $owner ?? '',
            'email' => $email ?? '', 'name' => $name ?? '', 'password' => $password,
        ], $this->root());

        if ($errors !== []) {
            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key, ['min' => 12]));
            }

            return 2;
        }

        return $this->report($output, $this->provisioner->create($this->root(), null, $clean), \sprintf('Account %s (%s) created.', $clean['username'], $clean['domain']));
    }

    public function suspend(Output $output, string $username, ?string $reason = null): int
    {
        $row = $this->find($output, $username);

        return $row === null ? 1 : $this->report($output, $this->provisioner->suspend($this->root(), null, (int) $row['id'], $reason ?? ''), $username . ' suspended.');
    }

    public function unsuspend(Output $output, string $username): int
    {
        $row = $this->find($output, $username);

        return $row === null ? 1 : $this->report($output, $this->provisioner->unsuspend($this->root(), null, (int) $row['id']), $username . ' unsuspended.');
    }

    public function package(Output $output, string $username, string $package): int
    {
        $row = $this->find($output, $username);

        if ($row === null) {
            return 1;
        }

        $target = $this->rules->package($this->root(), $row['owner_id'], $package);

        if ($target === null) {
            $output->error('No such package for this account\'s owner.');

            return 2;
        }

        return $this->report($output, $this->provisioner->changePackage($this->root(), null, (int) $row['id'], (int) $target['id']), $username . ' moved to ' . $target['name'] . '.');
    }

    public function terminate(Output $output, string $username, bool $yes = false): int
    {
        $row = $this->find($output, $username);

        if ($row === null) {
            return 1;
        }

        if (!$yes) {
            $output->error(\sprintf('This removes %s, its home folder and everything in it. Run again with --yes to do it.', $username));

            return 2;
        }

        return $this->report($output, $this->provisioner->terminate($this->root(), null, (int) $row['id']), $username . ' terminated.');
    }

    public function settle(Output $output): int
    {
        $counts = $this->provisioner->settleAll();

        if ($counts !== []) {
            $output->line(\implode(', ', \array_map(static fn(string $k, int $v): string => "{$v} {$k}", \array_keys($counts), $counts)));
        }

        return 0;
    }

    public function usage(Output $output): int
    {
        if (!$this->provisioner->refreshUsage()) {
            $output->error('The agent could not be asked for disk use.');

            return 1;
        }

        return 0;
    }

    private function report(Output $output, Outcome $outcome, string $done): int
    {
        switch ($outcome->status) {
            case Outcome::DONE:
                $output->success($done);

                return 0;
            case Outcome::PENDING:
                $output->line($outcome->message !== ''
                    ? 'Recorded; the agent is not reachable (' . $outcome->message . '). accounts:settle will finish it.'
                    : 'Recorded; the agent is working on it. accounts:list shows when it is done.');

                return 0;
            case Outcome::BUSY:
                $output->error($this->localization->get('Accounts.busy'));

                return 1;
            case Outcome::REFUSED:
                $output->error($this->localization->get((string) $outcome->code));

                return 1;
            default:
                $output->error($this->localization->get(AccountPages::agentMessage($outcome)) . ' (' . $outcome->code . ': ' . $outcome->message . ')');

                return 1;
        }
    }

    /** @return array<string, mixed>|null */
    private function find(Output $output, string $username): ?array
    {
        $row = $this->accounts->findByUsernameUnscoped($username);

        if ($row === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $row;
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
