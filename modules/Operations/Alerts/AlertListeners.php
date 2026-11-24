<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Http\Request;

/**
 * What the rest of the panel tells the alerts, through its hooks: the
 * watchdog (ops.service_event), updates (ops.update_event), backups
 * (backups.finished) and the root sign-in (auth.throttled).
 */
final class AlertListeners
{
    public function __construct(private readonly Alerts $alerts) {}

    /** ops.service_event: the watchdog restarted a service, could not, or gave up. */
    public function serviceEvent(string $unit, string $kind, string $detail): void
    {
        match ($kind) {
            'restarted' => $this->alerts->raise('services', 'services.' . $unit . '.restarted', 'warning', $unit . ' was down and has been restarted'),
            'restart_failed' => $this->alerts->raise('services', 'services.' . $unit . '.failed', 'critical', $unit . ' is down and could not be restarted', $detail),
            'gave_up' => $this->alerts->raise('services', 'services.' . $unit . '.gave_up', 'critical', $unit . ' keeps falling over: the watchdog has stopped restarting it', $detail),
            default => null,
        };
    }

    /** ops.update_event: updates are waiting, were installed, or failed. */
    public function updateEvent(string $kind, string $detail): void
    {
        $key = 'updates.' . $kind . '.' . \substr(\md5($detail), 0, 8);
        $text = \ucfirst($detail);

        match ($kind) {
            'failed' => $this->alerts->raise('updates', $key, 'critical', $text),
            'reboot' => $this->alerts->raise('updates', $key, 'warning', $text),
            'available' => $this->alerts->raise('updates', $key, \str_contains($detail, 'security') ? 'warning' : 'info', $text),
            'applied', 'upgraded' => $this->alerts->raise('updates', $key, 'info', $text),
            default => null,
        };
    }

    /** backups.finished: a run that did not back everything up. */
    public function backupFinished(int $run, string $state, int $ok, int $failed, string $destination, ?string $error): void
    {
        if ($state === 'done') {
            return;
        }

        $this->alerts->raise(
            'backups',
            'backups.run.' . $run,
            $state === 'partial' ? 'warning' : 'critical',
            $state === 'partial' ? 'A backup to ' . $destination . ' did not include every account' : 'A backup to ' . $destination . ' failed',
            \sprintf('%d account%s backed up, %d failed.', $ok, $ok === 1 ? '' : 's', $failed) . ($error === null || $error === '' || \preg_match('/^[A-Z][A-Za-z]+\.[a-z_]+$/', $error) === 1 ? '' : ' ' . $error),
        );
    }

    /** auth.throttled: someone is guessing at a sign-in. */
    public function signInThrottled(string $who, Request $request): void
    {
        $ip = $request->ip() ?? 'an unknown address';

        $this->alerts->raise('security', 'security.throttled.' . $who . '.' . $ip, 'warning', 'Too many wrong passwords for the ' . $who . ' sign-in from ' . $ip, 'The sign-in is locked for a while. If this was not you, see Brute-force blocking.');
    }
}
