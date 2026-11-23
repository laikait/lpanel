<?php

declare(strict_types=1);

namespace App\Modules\Security\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Security\Isolation\Isolation;

/** isolation:* from the shell, as root: the same settings as the page and the API. */
final class IsolationCommands
{
    public function __construct(
        private readonly Isolation $isolation,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** What was chosen and what the server has. */
    public function status(Output $output): int
    {
        $info = $this->isolation->info();
        $s = $this->isolation->settings();

        if ($info === null) {
            return $this->fail($output, 'Security.iso_error_unreachable');
        }

        $output->line(\sprintf('Other users\' processes hidden (hidepid): chosen %s, server %s%s.', $s['hidepid'] ? 'on' : 'off', $info['hidepid'] ? 'on' : 'off', ($info['hidepid_kept'] ?? false) ? ', kept across reboots' : ''));
        $output->line(\sprintf('Link protections: chosen %s, server %s%s.', $s['links'] ? 'on' : 'off', ($info['links_ok'] ?? false) ? 'on' : 'off', ($info['links_kept'] ?? false) ? ', kept across reboots' : ''));

        foreach ((array) ($info['links'] ?? []) as $l) {
            $output->line(\sprintf('  %-26s %d (wants %d)', (string) $l['name'], (int) $l['value'], (int) $l['want']));
        }

        return 0;
    }

    /** Change the settings: --hidepid on|off, --links on|off. */
    public function set(Output $output, ?string $hidepid = null, ?string $links = null): int
    {
        $changes = [];

        foreach (['hidepid' => $hidepid, 'links' => $links] as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (!\in_array($value, ['on', 'off'], true)) {
                $output->error('--' . $name . ' is "on" or "off".');

                return 1;
            }

            $changes[$name] = $value === 'on';
        }

        if ($changes === []) {
            $output->error('Nothing to change: give --hidepid or --links.');

            return 1;
        }

        [$ok, $why] = $this->isolation->configure($this->root(), null, $changes);

        if (!$ok) {
            return $this->fail($output, (string) $why);
        }

        $output->success('Saved.');

        return 0;
    }

    /** Send the settings to the server again, as the panel has them. */
    public function sync(Output $output): int
    {
        [$ok, $why] = $this->isolation->sync($this->root());

        if (!$ok) {
            return $this->fail($output, (string) $why);
        }

        $output->success('The server has the settings the panel has.');

        return 0;
    }

    private function fail(Output $output, string $why): int
    {
        $output->error(\str_starts_with($why, 'Security.') ? $this->localization->get($why) : $why);

        return 1;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
