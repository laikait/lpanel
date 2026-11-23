<?php

declare(strict_types=1);

namespace App\Modules\Security\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Security\BruteForce\BruteForce;

/** bruteforce:* from the shell, as root: the same settings as the page and the API. */
final class BruteForceCommands
{
    public function __construct(
        private readonly BruteForce $bruteForce,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** The settings and what the blocker has seen and done lately. */
    public function status(Output $output): int
    {
        $s = $this->bruteForce->settings();
        $o = $this->bruteForce->overview();
        $output->line(\sprintf('Blocking is %s: %d failed sign-ins within %d minutes block an address, first for %s.', $s['enabled'] ? 'on' : 'off', $s['threshold'], $s['window'], $s['duration']));
        $output->line(\sprintf('Failed sign-ins in the last hour: %d. Addresses blocked in the last day: %d.', $o['failures_hour'], $o['bans_day']));

        if ($o['missing'] !== []) {
            $output->warning('Logs not found at the last scan, so these are not watched: ' . \implode(', ', $o['missing']) . '.');
        }

        if ($o['top'] !== []) {
            $output->line();
            $output->line(\sprintf('%-40s %-6s %s', 'BUSIEST ADDRESSES', 'FAILED', 'ON'));

            foreach ($o['top'] as $t) {
                $output->line(\sprintf('%-40s %-6d %s', $t['ip'], $t['count'], \implode(', ', $t['services'])));
            }
        }

        if ($o['bans'] !== []) {
            $output->line();
            $output->line(\sprintf('%-40s %-20s %-6s %s', 'LATEST BLOCKS', 'AT (UTC)', 'FOR', 'ON'));

            foreach ($o['bans'] as $b) {
                $output->line(\sprintf('%-40s %-20s %-6s %s', (string) $b['cidr'], (string) $b['banned_at'], (string) $b['duration'], (string) $b['services']));
            }
        }

        return 0;
    }

    /** Change the settings: --on or --off, --threshold, --window (minutes), --for (1h, 1d, 7d, 30d). */
    public function set(Output $output, bool $on = false, bool $off = false, ?string $threshold = null, ?string $window = null, ?string $for = null): int
    {
        if ($on && $off) {
            $output->error('Choose --on or --off, not both.');

            return 1;
        }

        $changes = \array_filter(['threshold' => $threshold, 'window' => $window, 'duration' => $for], static fn(?string $v): bool => $v !== null);

        if ($on || $off) {
            $changes['enabled'] = $on;
        }

        if ($changes === []) {
            $output->error('Nothing to change: give --on, --off, --threshold, --window or --for.');

            return 1;
        }

        $why = $this->bruteForce->configure($this->root(), null, $changes);

        if ($why !== null) {
            $output->error($this->localization->get($why));

            return 1;
        }

        $output->success('Saved.');

        return 0;
    }

    /** Read the logs now, count, and block what is over the limit (this is what runs every minute). */
    public function scan(Output $output): int
    {
        $r = $this->bruteForce->scan();

        if ($r['error'] !== null) {
            $output->error(\str_starts_with($r['error'], 'Security.') ? $this->localization->get($r['error']) : $r['error']);

            return 1;
        }

        if ($r['read'] > 0 || $r['blocked'] !== []) {
            $output->line(\sprintf('%d failed sign-in(s) read; blocked: %s.', $r['read'], $r['blocked'] === [] ? 'none' : \implode(', ', $r['blocked'])));
        }

        return 0;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
