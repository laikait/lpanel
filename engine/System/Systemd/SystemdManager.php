<?php

declare(strict_types=1);

namespace App\Engine\System\Systemd;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Service\ServiceManager;

/**
 * Installs and removes an application's systemd units.
 *
 *     $manager->install($units, ['default', 'billing']);
 *     // writes the three files, daemon-reload, enable --now the timer and each worker
 *
 *     $manager->remove($units);
 *     // disable --now whatever is enabled, delete the files, daemon-reload
 *
 * **Privilege is the operating system's.** Unit files live in a directory
 * only root can write, and enabling a unit needs root. This refuses up front
 * when that is plainly missing rather than writing two of three files; it
 * neither escalates nor works around it.
 *
 * **Idempotent.** A file whose contents are unchanged is not rewritten, and
 * enabling an enabled unit is a no-op to systemd, so install is safe on every
 * deployment.
 *
 * systemctl is ServiceManager's to speak, as everywhere else: this writes and
 * deletes the files, and asks it to reload, enable and disable -- only ever
 * the units SystemdUnits generates.
 */
final class SystemdManager
{
    public const DIRECTORY = '/etc/systemd/system';

    /** Present exactly when systemd is PID 1: sd_booted()'s test. */
    public const RUNTIME_DIRECTORY = '/run/systemd/system';

    /**
     * @param ?string $runtimeDirectory where to look for a running systemd; null to skip the check
     * @param ?bool   $privileged       null to decide from the effective user id
     */
    public function __construct(
        private readonly ServiceManager $services,
        private readonly string $directory = self::DIRECTORY,
        private readonly ?SystemAudit $audit = null,
        private readonly ?string $runtimeDirectory = self::RUNTIME_DIRECTORY,
        private readonly ?bool $privileged = null,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /** Whether this application's scheduler timer is installed. */
    public function hasScheduler(SystemdUnits $units): bool
    {
        return \is_file($this->path($units->schedulerTimer()));
    }

    /**
     * Write the units, then reload systemd and enable the timer and a worker per queue.
     *
     * @param list<string> $queues the queues to start a worker on; none to install the files only
     *
     * @return array{written: list<string>, unchanged: list<string>, enabled: list<string>}
     *
     * @throws SystemdException
     */
    public function install(SystemdUnits $units, array $queues, bool $scheduler = true): array
    {
        $this->assertUsable();

        // Named before anything is written, so a bad queue fails with nothing changed.
        $enable = \array_map($units->worker(...), \array_values(\array_unique($queues)));

        if ($scheduler) {
            \array_unshift($enable, $units->schedulerTimer());
        }

        $written = [];
        $unchanged = [];

        foreach ($units->files() as $file) {
            $path = $this->path($file->name);

            if (\is_file($path) && \file_get_contents($path) === $file->contents) {
                $unchanged[] = $file->name;

                continue;
            }

            if (@\file_put_contents($path, $file->contents) === false) {
                throw SystemdException::unwritable($path);
            }

            @\chmod($path, 0o644);
            $written[] = $file->name;
        }

        $this->services->reloadUnitFiles();

        if ($enable !== []) {
            $this->services->enableUnits($units, $enable);
        }

        $this->audit?->record('system.systemd.installed', AuditOutcome::Succeeded, $units->prefix(), [
            'written' => \implode(' ', $written),
            'enabled' => \implode(' ', $enable),
        ]);

        return ['written' => $written, 'unchanged' => $unchanged, 'enabled' => $enable];
    }

    /**
     * Stop and disable everything of this application's that is enabled, then delete its files.
     *
     * @return array{disabled: list<string>, deleted: list<string>}
     *
     * @throws SystemdException
     */
    public function remove(SystemdUnits $units): array
    {
        $this->assertUsable();

        $disable = $this->enabled($units);

        if ($disable !== []) {
            $this->services->disableUnits($units, $disable);
        }

        $deleted = [];

        foreach ($units->files() as $file) {
            $path = $this->path($file->name);

            if (\is_file($path)) {
                if (!@\unlink($path)) {
                    throw SystemdException::unwritable($path);
                }

                $deleted[] = $file->name;
            }
        }

        if ($deleted !== []) {
            $this->services->reloadUnitFiles();
        }

        $this->audit?->record('system.systemd.removed', AuditOutcome::Succeeded, $units->prefix(), [
            'disabled' => \implode(' ', $disable),
            'deleted' => \implode(' ', $deleted),
        ]);

        return ['disabled' => $disable, 'deleted' => $deleted];
    }

    /**
     * The units of this application that are enabled: the timer, and every
     * worker instance, found by the symlinks `enable` leaves in *.wants/.
     *
     * @return list<string>
     */
    public function enabled(SystemdUnits $units): array
    {
        $found = [];
        $pattern = \preg_quote($units->prefix(), '/');

        foreach (\glob($this->directory . '/*.wants/*') ?: [] as $link) {
            $name = \basename($link);

            if ($name === $units->schedulerTimer() || \preg_match('/^' . $pattern . '-worker@[^.]+\.service$/', $name) === 1) {
                $found[] = $name;
            }
        }

        $found = \array_values(\array_unique($found));
        \sort($found);

        return $found;
    }

    private function assertUsable(): void
    {
        // No platform test here: /run/systemd/system exists only where systemd
        // is running, and ServiceManager refuses Windows before anything runs.
        if ($this->runtimeDirectory !== null && !\is_dir($this->runtimeDirectory)) {
            throw SystemdException::unsupportedPlatform();
        }

        if (!($this->privileged ?? self::isRoot()) || !\is_dir($this->directory) || !\is_writable($this->directory)) {
            throw SystemdException::notPrivileged($this->directory);
        }
    }

    private static function isRoot(): bool
    {
        return \function_exists('posix_geteuid') && \posix_geteuid() === 0;
    }

    private function path(string $name): string
    {
        return \rtrim($this->directory, '/') . '/' . $name;
    }
}
