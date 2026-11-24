<?php

declare(strict_types=1);

namespace App\Modules\Operations\Services;

use App\Engine\Hook\HookEngine;
use App\Modules\Audit\AuditLog;

/**
 * The service watchdog: every minute it looks at the services, and one that
 * should be up and is not is restarted.
 *
 * "Should be up" is: it starts at boot, nobody stopped it on purpose through
 * the panel, and the watchdog is not turned off for it. One that is starting,
 * reloading or stopping is left alone (a reload of the web server must not be
 * answered with a restart), and a service must have been found down for
 * GRACE seconds before it is restarted, so that something that is only taking
 * a moment is not interrupted. A service is restarted at most three times an hour; after that the watchdog gives up on it, says so
 * (the bell, the log) and leaves it, as a service that cannot stay up needs a
 * person. Each thing that happens is written down and announced through the
 * `ops.service_event` hook ($unit, $kind, $detail), which alerts will listen to.
 */
final class Watchdog
{
    /** Restarts the watchdog tries for one service in an hour. */
    public const MAX_PER_HOUR = 3;

    /** How long a service is found down before the watchdog restarts it, in seconds. */
    public const GRACE = 20;

    public function __construct(
        private readonly Services $services,
        private readonly AuditLog $audit,
        private readonly HookEngine $hooks,
    ) {}

    /**
     * @return array{checked: int, down: list<string>, restarted: list<string>, failed: list<string>, gave_up: list<string>, recovered: list<string>, error: bool}
     */
    public function run(?int $grace = null): array
    {
        $grace ??= self::GRACE;
        $out = ['checked' => 0, 'down' => [], 'restarted' => [], 'failed' => [], 'gave_up' => [], 'recovered' => [], 'error' => false];
        $list = $this->services->list();

        if ($list === null) {
            $out['error'] = true;

            return $out;
        }

        foreach ($list as $s) {
            $unit = (string) $s['unit'];
            $out['checked']++;

            if ($s['running']) {
                if ($s['down_since'] !== null) {
                    $this->services->setRow($unit, ['down_since' => null]);
                    $this->announce($unit, 'recovered', 'is running again');
                    $out['recovered'][] = $unit;
                }

                continue;
            }

            // On its way up, or down, or reloading: wait and see.
            if (!empty($s['transitional'])) {
                continue;
            }

            // Not up, and not meant to be: it does not start at boot, an administrator stopped it, or the watchdog is off for it.
            if (!$s['expected'] || !$s['watch']) {
                if ($s['down_since'] !== null) {
                    $this->services->setRow($unit, ['down_since' => null]);
                }

                continue;
            }

            $out['down'][] = $unit;

            if ($s['down_since'] === null) {
                $this->services->setRow($unit, ['down_since' => \gmdate('Y-m-d H:i:s')]);
                $this->announce($unit, 'down', (string) ($s['active'] ?? '') . '/' . (string) ($s['sub'] ?? ''));
            }

            // Found down just now: the next round restarts it, if it is still down.
            if ($s['down_since'] === null ? $grace > 0 : \time() - (int) \strtotime((string) $s['down_since'] . ' UTC') < $grace) {
                continue;
            }

            if ($this->services->attempts($unit) >= self::MAX_PER_HOUR) {
                if (!$this->gaveUpSince($unit, (string) ($s['down_since'] ?? ''))) {
                    $this->announce($unit, 'gave_up', 'did not stay up after ' . self::MAX_PER_HOUR . ' restarts');
                    $out['gave_up'][] = $unit;
                }

                continue;
            }

            [$ok, $why] = $this->services->act(null, null, $unit, 'restart');
            $this->audit->record('service.watchdog', null, null, $unit, $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? [] : ['error' => (string) $why]);

            if ($ok) {
                $this->announce($unit, 'restarted', '');
                $out['restarted'][] = $unit;
            } else {
                $this->announce($unit, 'restart_failed', (string) $why);
                $out['failed'][] = $unit;
            }
        }

        $this->services->tidy();

        return $out;
    }

    private function announce(string $unit, string $kind, string $detail): void
    {
        $this->services->event($unit, $kind, $detail);
        $this->hooks->do('ops.service_event', $unit, $kind, $detail);
    }

    private function gaveUpSince(string $unit, string $downSince): bool
    {
        foreach ($this->services->events(10, $unit) as $e) {
            if ($e['kind'] === 'gave_up' && (string) $e['at'] >= $downSince) {
                return true;
            }
        }

        return false;
    }
}
