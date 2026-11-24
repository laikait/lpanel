<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Services\Services;
use App\Modules\Operations\Services\Watchdog;

/** services:* and watchdog:run from the shell, as root: the same as the page and the API. */
final class ServiceCommands
{
    public function __construct(
        private readonly Services $services,
        private readonly Watchdog $watchdog,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** Every service, how it is doing and what the watchdog knows. */
    public function status(Output $output): int
    {
        $list = $this->services->list();

        if ($list === null) {
            $output->error($this->localization->get('Operations.error_unreachable'));

            return 1;
        }

        $output->line(\sprintf('%-28s %-9s %-8s %-8s %s', 'SERVICE', 'STATE', 'BOOT', 'WATCHED', 'NOTE'));

        foreach ($list as $s) {
            $note = $s['hold'] ? 'stopped on purpose' : (!$s['running'] && $s['expected'] ? 'DOWN' . ($s['down_since'] !== null ? ' since ' . (string) $s['down_since'] . ' UTC' : '') : '');
            $output->line(\sprintf('%-28s %-9s %-8s %-8s %s', (string) $s['unit'], (string) $s['active'], $s['enabled'] ? 'enabled' : 'disabled', $s['watch'] ? 'yes' : 'no', $note));
        }

        return 0;
    }

    public function start(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'start');
    }

    public function stop(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'stop');
    }

    public function restart(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'restart');
    }

    public function reload(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'reload');
    }

    public function enable(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'enable');
    }

    public function disable(Output $output, string $unit): int
    {
        return $this->act($output, $unit, 'disable');
    }

    /** Turn the watchdog on or off for a service (--off). */
    public function watch(Output $output, string $unit, bool $off = false): int
    {
        $this->services->setWatch($this->root(), null, $this->unit($unit), !$off);
        $output->success(\sprintf('%s is %s.', $this->unit($unit), $off ? 'no longer watched' : 'watched'));

        return 0;
    }

    /** One round of the watchdog (this runs every minute); --grace is how many seconds a service is found down before it is restarted. */
    public function watchdog(Output $output, ?string $grace = null): int
    {
        $r = $this->watchdog->run($grace !== null && \ctype_digit($grace) ? (int) $grace : null);

        if ($r['error']) {
            $output->error($this->localization->get('Operations.error_unreachable'));

            return 1;
        }

        foreach (['restarted' => 'Restarted', 'failed' => 'Could not restart', 'gave_up' => 'Gave up on', 'recovered' => 'Running again'] as $kind => $label) {
            if ($r[$kind] !== []) {
                $output->line($label . ': ' . \implode(', ', $r[$kind]) . '.');
            }
        }

        return 0;
    }

    private function act(Output $output, string $unit, string $action): int
    {
        $unit = $this->unit($unit);
        [$ok, $why] = $this->services->act($this->root(), null, $unit, $action);

        if (!$ok) {
            $output->error(\str_starts_with((string) $why, 'Operations.') ? $this->localization->get((string) $why) : (string) $why);

            return 1;
        }

        $output->success(\sprintf('%s: %s done.', $unit, $action));

        return 0;
    }

    /** "httpd" is "httpd.service"; a name with a dot already says what it is (a timer, a socket). */
    private function unit(string $name): string
    {
        return \str_contains($name, '.') ? $name : $name . '.service';
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
