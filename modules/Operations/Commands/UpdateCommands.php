<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Updates\Updates;
use App\Modules\Operations\Updates\UpdateSettings;

/** updates:* from the shell, as root: the same as the Updates page and the API. */
final class UpdateCommands
{
    /** How long --wait waits, in seconds (the agent's own limit for an update is two hours). */
    private const WAIT = 7200;

    public function __construct(
        private readonly Updates $updates,
        private readonly UpdateSettings $settings,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** What the last check found, what runs now, and the latest runs. */
    public function status(Output $output): int
    {
        $s = $this->settings->get();
        $latest = $this->updates->latest();
        $busy = $this->updates->busy();

        $output->line('LPanel     ' . ($this->updates->installed() ?: 'development copy'));

        if ($latest === null) {
            $output->line('checked    never (updates:check)');
        } else {
            $output->line('checked    ' . $latest['checked_at'] . ' UTC');
            $output->line(\sprintf('waiting    %d packages, %d with a security erratum', \count($latest['packages']), $latest['security']));

            foreach ($latest['php'] as $p) {
                $output->line(\sprintf('php        %s.%s: %s -> %s', \substr((string) $p['version'], 0, 1), \substr((string) $p['version'], 1), (string) $p['from'], (string) $p['to']));
            }

            if ($latest['reboot']['needed']) {
                $output->line('reboot     needed (' . \implode(', ', $latest['reboot']['why']) . ')' . ($latest['reboot']['scheduled'] ? ', on its way' : ''));
            }

            if ($latest['restart'] !== []) {
                $output->line('restart    ' . \implode(' ', $latest['restart']));
            }

            $p = $latest['panel'];
            $output->line('release    ' . match (true) {
                !$p['channel'] => 'no update channel',
                $p['error'] !== '' => 'the channel could not be read: ' . $p['error'],
                $p['newer'] => $p['latest'] . ' is out (updates:panel ' . $p['latest'] . ')',
                default => 'up to date (' . $p['latest'] . ')',
            });
        }

        $output->line(\sprintf('automatic  system: %s, PHP: %s, at %02d:00; LPanel: %s; reboot: %s%s', $s['os'], $s['php'] ? 'yes' : 'no', $s['hour'], $s['panel'], $s['reboot'] ? 'yes' : 'no', $s['exclude'] !== [] ? '; never: ' . \implode(' ', $s['exclude']) : ''));

        if ($busy !== null) {
            $output->line('running    ' . self::label($busy) . ' since ' . (string) $busy['started_at'] . ' UTC');
        }

        $output->line('');
        $output->line(\sprintf('%-5s %-20s %-11s %-20s %s', 'ID', 'WHAT', 'STATE', 'STARTED (UTC)', 'NOTE'));

        foreach ($this->updates->runs(10) as $r) {
            $output->line(\sprintf('%-5d %-20s %-11s %-20s %s', (int) $r['id'], self::label($r), (string) $r['state'], (string) $r['started_at'], $this->note($r)));
        }

        return 0;
    }

    /** Ask what can be updated (--wait for the answer). */
    public function check(Output $output, bool $wait = false): int
    {
        [$run, $why] = $this->updates->check($this->root(), null);

        return $this->started($output, $run, $why, $wait);
    }

    /** Install updates: all (the default), --security, --php, or --packages=a,b. */
    public function apply(Output $output, bool $security = false, bool $php = false, ?string $packages = null, bool $wait = false): int
    {
        $scope = match (true) {
            $packages !== null => 'packages',
            $security => 'security',
            $php => 'php',
            default => 'all',
        };
        $names = $packages === null ? [] : \array_values(\array_filter(\preg_split('/[\s,]+/', $packages) ?: [], static fn(string $n): bool => $n !== ''));
        [$run, $why] = $this->updates->apply($this->root(), null, $scope, $names);

        return $this->started($output, $run, $why, $wait);
    }

    /** Upgrade LPanel to the release the channel offers (or the one named). */
    public function panel(Output $output, ?string $version = null, bool $wait = false): int
    {
        if ($version === null) {
            $latest = $this->updates->latest();

            if ($latest === null || !$latest['panel']['newer']) {
                $output->error($this->localization->get('Operations.updates_panel_none'));

                return 1;
            }

            $version = $latest['panel']['latest'];
        }

        [$run, $why] = $this->updates->upgrade($this->root(), null, $version);

        return $this->started($output, $run, $why, $wait);
    }

    /** Reboot the server a minute from now (--yes), or call that off (--cancel). */
    public function reboot(Output $output, bool $yes = false, bool $cancel = false): int
    {
        if (!$yes && !$cancel) {
            $output->error('The server reboots a minute from now: every site, mailbox and session goes away for a while. Say --yes.');

            return 1;
        }

        [$ok, $why] = $this->updates->reboot($this->root(), null, $cancel);

        if (!$ok) {
            $output->error($this->text((string) $why));

            return 1;
        }

        $output->success($cancel ? 'The reboot is called off.' : 'The server reboots in a minute (updates:reboot --cancel calls it off).');

        return 0;
    }

    /** Change what is installed by itself, and when. */
    public function setSettings(Output $output, ?string $os = null, ?string $php = null, ?string $hour = null, ?string $exclude = null, ?string $panel = null, ?string $reboot = null): int
    {
        $input = \array_filter(['os' => $os, 'php' => $php, 'hour' => $hour, 'exclude' => $exclude, 'panel' => $panel, 'reboot' => $reboot], static fn(?string $v): bool => $v !== null);
        [$s, $errors] = $this->settings->validate($input);

        if ($errors !== []) {
            foreach ($errors as $key) {
                $output->error($this->localization->get($key));
            }

            return 1;
        }

        if ($input !== []) {
            $this->settings->save($s, $this->root(), null);
        }

        $output->line(\sprintf('system updates  %s', $s['os']));
        $output->line(\sprintf('PHP updates     %s', $s['php'] ? 'yes' : 'no'));
        $output->line(\sprintf('hour            %02d:00 (server time)', $s['hour']));
        $output->line(\sprintf('never update    %s', $s['exclude'] === [] ? '-' : \implode(' ', $s['exclude'])));
        $output->line(\sprintf('LPanel          %s', $s['panel']));
        $output->line(\sprintf('reboot          %s', $s['reboot'] ? 'yes, when an update needs it' : 'no'));

        return 0;
    }

    /** Settle runs with the agent (every minute). */
    public function settle(Output $output): int
    {
        $n = $this->updates->settle();

        if ($n > 0) {
            $output->line($n . ' finished.');
        }

        return 0;
    }

    /** Start what is due: the daily check, and in the night's hour what the settings install (every minute). */
    public function due(Output $output): int
    {
        $this->updates->settle();
        $started = $this->updates->due();

        if ($started !== null) {
            $output->line('Started: ' . $started . '.');
        }

        return 0;
    }

    /** @param array<string, mixed>|null $run */
    private function started(Output $output, ?array $run, ?string $why, bool $wait): int
    {
        if ($why !== null || $run === null) {
            $output->error($this->text((string) $why));

            return 1;
        }

        if (!$wait) {
            $output->success(self::label($run) . ' started (run ' . (int) $run['id'] . '; updates:status shows how it goes).');

            return 0;
        }

        $until = \time() + self::WAIT;

        while (\in_array($run['state'] ?? '', [Updates::RUNNING, Updates::INSTALLING], true) && \time() < $until) {
            \sleep(2);
            $this->updates->settle();
            $run = $this->updates->find((int) $run['id']) ?? [];
        }

        if (($run['state'] ?? '') !== Updates::DONE) {
            $output->error(self::label($run) . ' ' . (string) ($run['state'] ?? 'lost') . ': ' . $this->text((string) ($run['error'] ?? '')));

            foreach ((array) (($run['result'] ?? [])['log'] ?? []) as $line) {
                $output->line('  ' . (string) $line);
            }

            return 1;
        }

        $r = (array) $run['result'];

        if ($run['kind'] === 'check') {
            $output->success(\sprintf('%d packages can be updated, %d with a security erratum.', \count((array) ($r['packages'] ?? [])), (int) ($r['security'] ?? 0)));

            foreach ((array) ($r['packages'] ?? []) as $p) {
                $output->line(\sprintf('  %-40s %-28s %s', (string) $p['name'], (string) $p['to'], (string) ($p['kind'] === 'security' ? 'security ' . $p['severity'] : '')));
            }

            $panel = (array) ($r['panel'] ?? []);

            if (($panel['newer'] ?? false) === true) {
                $output->line('LPanel ' . (string) $panel['latest'] . ' is out.');
            }
        } elseif ($run['kind'] === 'os') {
            $output->success(\sprintf('%d packages updated.', \count((array) ($r['upgraded'] ?? []))));

            foreach ((array) ($r['upgraded'] ?? []) as $p) {
                $output->line(\sprintf('  %-40s %s -> %s', (string) $p['name'], (string) ($p['from'] ?: 'new'), (string) $p['to']));
            }

            foreach (['restarted' => 'Restarted', 'restart_failed' => 'Did not come back', 'soon' => 'Restarting shortly', 'pending' => 'Still running old code'] as $k => $label) {
                if (!empty($r[$k])) {
                    $output->line($label . ': ' . \implode(' ', (array) $r[$k]));
                }
            }

            if (!empty(((array) ($r['reboot'] ?? []))['needed'])) {
                $output->line('The server needs a reboot (updates:reboot --yes).');
            }
        } else {
            $output->success('LPanel ' . (string) $run['target'] . ' is installed.');
        }

        return 0;
    }

    /** @param array<string, mixed> $r */
    private static function label(array $r): string
    {
        return match ((string) ($r['kind'] ?? '')) {
            'check' => 'check',
            'os' => 'update (' . (string) $r['scope'] . ')',
            'panel' => 'upgrade to ' . (string) $r['target'],
            'reboot' => $r['scope'] === 'cancel' ? 'reboot called off' : 'reboot',
            default => (string) ($r['kind'] ?? '?'),
        };
    }

    /** @param array<string, mixed> $r */
    private function note(array $r): string
    {
        if ($r['state'] === Updates::FAILED) {
            return $this->text((string) $r['error']);
        }

        $res = (array) $r['result'];

        return match ((string) $r['kind']) {
            'check' => $r['state'] === Updates::DONE ? \sprintf('%d waiting, %d security', \count((array) ($res['packages'] ?? [])), (int) ($res['security'] ?? 0)) : '',
            'os' => $r['state'] === Updates::DONE ? \count((array) ($res['upgraded'] ?? [])) . ' updated' : '',
            default => '',
        };
    }

    private function text(string $why): string
    {
        return \str_starts_with($why, 'Operations.') ? $this->localization->get($why) : $why;
    }

    private function root(): \App\Engine\Auth\Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
