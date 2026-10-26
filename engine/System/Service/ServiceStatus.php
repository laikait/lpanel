<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

/**
 * What systemd says about one service, at the moment it was asked.
 *
 * The four states are systemd's own words, kept as they are -- "active",
 * "activating", "failed", "masked" -- because a translation into this
 * framework's vocabulary would lose exactly the case somebody is debugging.
 * The questions below answer the common ones.
 */
final class ServiceStatus
{
    public function __construct(
        public readonly string $unit,
        /** loaded, not-found, masked, error, ... */
        public readonly string $loadState,
        /** active, inactive, activating, deactivating, failed, reloading */
        public readonly string $activeState,
        /** running, exited, dead, failed, ... */
        public readonly string $subState,
        /** enabled, disabled, static, masked, ... ('' when there is no unit file) */
        public readonly string $unitFileState,
    ) {}

    /** Parse `systemctl show --property=...` output: one Key=value per line. */
    public static function fromShow(string $unit, string $output): self
    {
        $values = [];

        foreach (\explode("\n", $output) as $line) {
            $pair = \explode('=', \rtrim($line, "\r"), 2);

            if (\count($pair) === 2) {
                $values[$pair[0]] = $pair[1];
            }
        }

        return new self(
            $unit,
            $values['LoadState'] ?? '',
            $values['ActiveState'] ?? '',
            $values['SubState'] ?? '',
            $values['UnitFileState'] ?? '',
        );
    }

    /** Whether systemd knows a unit by this name. */
    public function exists(): bool
    {
        return $this->loadState !== '' && $this->loadState !== 'not-found';
    }

    public function isActive(): bool
    {
        return $this->activeState === 'active';
    }

    public function isFailed(): bool
    {
        return $this->activeState === 'failed';
    }

    /** Starts at boot. */
    public function isEnabled(): bool
    {
        return $this->unitFileState === 'enabled';
    }
}
