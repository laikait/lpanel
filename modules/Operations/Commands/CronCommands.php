<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Cron\CronJobs;

/** cron:* from the shell, as root: an account's scheduled jobs, the same as the page and the API. */
final class CronCommands
{
    public function __construct(
        private readonly CronJobs $jobs,
        private readonly Accounts $accounts,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** The account's jobs. */
    public function list(Output $output, string $username): int
    {
        $account = $this->find($output, $username);

        if ($account === null) {
            return 1;
        }

        $jobs = $this->jobs->forAccount((int) $account['id']);

        if ($jobs === []) {
            $output->line($username . ' has no scheduled jobs.');

            return 0;
        }

        $output->line(\sprintf('%-5s %-20s %s', 'ID', 'SCHEDULE', 'COMMAND'));

        foreach ($jobs as $j) {
            $output->line(\sprintf('%-5d %-20s %s', (int) $j['id'], (string) $j['schedule'], (string) $j['command']));
        }

        return 0;
    }

    /** Schedule a command (five fields in quotes, or @daily): it runs as the account. */
    public function add(Output $output, string $username, string $schedule, string $command): int
    {
        $account = $this->find($output, $username);

        if ($account === null) {
            return 1;
        }

        [$id, $errors] = $this->jobs->add($this->root(), null, $account, $schedule, $command);

        if ($id === null) {
            foreach ($errors as $field => $text) {
                $output->error($field . ': ' . (\str_starts_with($text, 'Operations.') ? $this->localization->get($text) : $text));
            }

            return 1;
        }

        $output->success(\sprintf('Job %d is scheduled.', $id));

        return 0;
    }

    /** Remove a job (its id is in cron:list). */
    public function remove(Output $output, string $username, string $id): int
    {
        $account = $this->find($output, $username);

        if ($account === null) {
            return 1;
        }

        [$ok, $why] = $this->jobs->remove($this->root(), null, $account, (int) $id);

        if (!$ok) {
            $output->error(\str_starts_with((string) $why, 'Operations.') ? $this->localization->get((string) $why) : (string) $why);

            return 1;
        }

        $output->success('The job is removed.');

        return 0;
    }

    /** @return array<string, mixed>|null */
    private function find(Output $output, string $username): ?array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $account;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
