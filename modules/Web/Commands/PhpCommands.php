<?php

declare(strict_types=1);

namespace App\Modules\Web\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\Bandwidth;
use App\Modules\Web\PhpVersions;
use App\Modules\Web\WebSync;

/**
 * php:* (the server's PHP versions) and web:traffic / web:bandwidth from the
 * shell, as root: the same actions as the Admin panel and the API.
 */
final class PhpCommands
{
    /** How long a command waits for a job before leaving it to web:settle. */
    private const WAIT_SECONDS = 1800;

    public function __construct(
        private readonly PhpVersions $php,
        private readonly Bandwidth $bandwidth,
        private readonly Accounts $accounts,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function list(Output $output): int
    {
        $this->php->settleAll();
        $info = $this->php->info();

        if ($info === null) {
            $output->error($this->localization->get('Web.php_agent_down'));

            return 1;
        }

        $sites = $this->php->sitesByVersion();
        $default = $this->php->defaultVersion();
        $output->line(\sprintf('%-8s %-10s %-6s %-30s %s', 'VERSION', 'STATE', 'SITES', 'NOTES', 'EXTENSIONS'));

        foreach (\array_reverse($info['versions']) as $v) {
            $notes = \array_filter([
                $v['version'] === $default ? 'default' : '',
                $v['panel'] ? 'the panel runs on it' : '',
                \in_array($v['version'], PhpVersions::END_OF_LIFE, true) ? 'end of life' : '',
            ]);
            $output->line(\sprintf(
                '%-8s %-10s %-6s %-30s %s',
                WebSync::phpLabel((string) $v['version']),
                $v['installed'] ? ($v['running'] ? 'running' : 'installed') : '-',
                $v['installed'] ? (string) ($sites[(string) $v['version']] ?? 0) : '',
                \implode(', ', $notes),
                \implode(',', (array) $v['extensions']),
            ));
        }

        $running = $this->php->running();

        if ($running !== null) {
            $output->line(\sprintf('In progress: %s %s (job %d)', $running['op'], WebSync::phpLabel((string) $running['version']), $running['id']));
        }

        return 0;
    }

    public function install(Output $output, string $version, bool $noWait = false): int
    {
        return $this->follow($output, $this->php->install($this->root(), null, self::digits($version)), $noWait);
    }

    public function remove(Output $output, string $version, bool $noWait = false): int
    {
        return $this->follow($output, $this->php->remove($this->root(), null, self::digits($version)), $noWait);
    }

    /** --add=redis,imagick --remove=gd: changes to the version's current set. */
    public function extensions(Output $output, string $version, ?string $add = null, ?string $remove = null, bool $noWait = false): int
    {
        $version = self::digits($version);
        $have = (array) ($this->php->version($version)['extensions'] ?? []);
        $split = static fn(?string $s): array => $s === null ? [] : \array_values(\array_filter(\array_map('trim', \explode(',', $s))));
        $wanted = \array_values(\array_diff([...$have, ...$split($add)], $split($remove)));

        return $this->follow($output, $this->php->extensions($this->root(), null, $version, $wanted), $noWait);
    }

    public function setDefault(Output $output, string $version): int
    {
        $why = $this->php->setDefault($this->root(), null, self::digits($version));

        if ($why !== null) {
            $output->error($this->localization->get($why));

            return 2;
        }

        $output->success('New sites get PHP ' . WebSync::phpLabel($this->php->defaultVersion()) . '.');

        return 0;
    }

    /** Fetch the agent's bandwidth counts, then stop or restart sites as each package says. */
    public function traffic(Output $output): int
    {
        if (!$this->bandwidth->refresh()) {
            $output->error('The agent could not be asked.');

            return 1;
        }

        $counts = $this->bandwidth->enforce();

        if ($counts['limited'] + $counts['restored'] > 0) {
            $output->line(\sprintf('%d stopped for bandwidth, %d started again', $counts['limited'], $counts['restored']));
        }

        return 0;
    }

    public function bandwidth(Output $output, string $username): int
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));

            return 1;
        }

        $u = $this->bandwidth->usage($account);
        $output->line(\sprintf('used     %d bytes this month', $u['used']));
        $output->line('limit    ' . ($u['limit'] > 0 ? $u['limit'] . ' bytes (' . $u['percent'] . '%), then ' . $u['action'] : 'none'));
        $output->line('sites    ' . ($u['limited'] ? 'stopped: the bandwidth is used up' : 'running'));

        return 0;
    }

    // -------------------------------------------------------------------------

    /** @param array{0: ?array<string, mixed>, 1: ?string} $result */
    private function follow(Output $output, array $result, bool $noWait): int
    {
        [$job, $why] = $result;

        if ($job === null) {
            $output->error($this->localization->get((string) $why));

            return 2;
        }

        $id = (int) $job['id'];
        $label = $job['op'] . ' ' . WebSync::phpLabel((string) $job['version']);

        if ($noWait && $job['state'] === PhpVersions::RUNNING) {
            $output->line(\sprintf('%s started (job %d); web:settle follows it.', $label, $id));

            return 0;
        }

        $until = \time() + self::WAIT_SECONDS;

        while ($job['state'] === PhpVersions::RUNNING && \time() < $until) {
            \sleep(3);
            $this->php->settleAll();
            $job = $this->php->job($id) ?? $job;
        }

        return match ($job['state']) {
            PhpVersions::DONE => $this->done($output, $label . ' done.'),
            PhpVersions::FAILED => $this->failed($output, $label . ' failed: ' . $job['error']),
            default => $this->done($output, \sprintf('%s is still running (job %d); web:settle follows it.', $label, $id)),
        };
    }

    private function done(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function failed(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    private static function digits(string $version): string
    {
        return \str_replace('.', '', $version);
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
