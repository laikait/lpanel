<?php

declare(strict_types=1);

namespace App\Engine\System\Permission;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Filesystem\FilesystemPolicy;

/**
 * Changing who owns a path and what they may do with it.
 *
 *     $permissions = new PermissionManager(
 *         FilesystemPolicy::none()->allowWrite('/srv/app/storage'),
 *         owners: ['www-data'],
 *         groups: ['www-data'],
 *     );
 *
 *     $permissions->chmod('/srv/app/storage/uploads', 0o775);
 *     $permissions->chgrp('/srv/app/storage/uploads', 'www-data');
 *
 * **Every change is privileged, so every one is bounded twice.** The path must
 * be one the filesystem policy allows *writing* -- with the same resolution,
 * symlinks followed and checked, ".." refused. And:
 *
 * - **A mode is 0 to 0777.** setuid, setgid and sticky bits are refused: a
 *   setuid file is a way to run code as its owner, and nothing an application
 *   does needs to make one.
 * - **Nothing is made world-writable.** o+w on a server is how one compromised
 *   account becomes all of them; grant a group instead.
 * - **Ownership goes only to the users and groups named when this was made.**
 *   There is no default and no wildcard, so "chown root" is refused unless root
 *   was listed on purpose.
 *
 * Checked in that order, then the path, then the platform, and only then is
 * anything changed.
 *
 * **Privilege is the operating system's.** chown needs root, chmod needs to own
 * the file; this class does not escalate, and a refusal from the kernel is an
 * exception that says nothing changed. Windows has neither concept in the POSIX
 * sense, and every change is refused there rather than half-applied.
 */
final class PermissionManager
{
    private const NAME = '/^[a-z_][a-z0-9_-]{0,31}$/D';

    /** @var list<string> */
    private readonly array $owners;

    /** @var list<string> */
    private readonly array $groups;

    /**
     * @param list<string>  $owners users files may be given to
     * @param list<string>  $groups groups files may be given to
     * @param ?SystemAudit  $audit  when given, system.permission.changed, .refused and .failed are recorded
     */
    public function __construct(
        private readonly FilesystemPolicy $filesystem,
        array $owners = [],
        array $groups = [],
        private readonly ?SystemAudit $audit = null,
    ) {
        foreach ($owners as $owner) {
            self::checkName('user', $owner);
        }

        foreach ($groups as $group) {
            self::checkName('group', $group);
        }

        $this->owners = \array_values($owners);
        $this->groups = \array_values($groups);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->audited($path, ['operation' => 'chmod', 'mode' => \sprintf('%04o', $mode)], fn() => $this->changeMode($path, $mode));
    }

    public function chown(string $path, string $user): void
    {
        $this->audited($path, ['operation' => 'chown', 'user' => $user], fn() => $this->changeOwner($path, $user));
    }

    public function chgrp(string $path, string $group): void
    {
        $this->audited($path, ['operation' => 'chgrp', 'group' => $group], fn() => $this->changeGroup($path, $group));
    }

    /** @param array<string, string|int|float|bool|null> $context */
    private function audited(string $path, array $context, \Closure $change): void
    {
        try {
            $change();
        } catch (PermissionException|FilesystemException $e) {
            $this->audit?->record(
                $e->isRefusal() ? 'system.permission.refused' : 'system.permission.failed',
                $e->isRefusal() ? AuditOutcome::Refused : AuditOutcome::Failed,
                $path,
                $context,
            );

            throw $e;
        }

        $this->audit?->record('system.permission.changed', AuditOutcome::Succeeded, $path, $context);
    }

    private function changeMode(string $path, int $mode): void
    {
        if ($mode < 0 || $mode > 0o777) {
            throw PermissionException::invalidMode($mode);
        }

        if (($mode & 0o002) !== 0) {
            throw PermissionException::worldWritable($mode);
        }

        $resolved = $this->target($path);

        if (!@\chmod($resolved, $mode)) {
            throw PermissionException::failed('change the mode of', $path);
        }

        \clearstatcache(true, $resolved);
    }

    private function changeOwner(string $path, string $user): void
    {
        self::checkName('user', $user);

        if (!\in_array($user, $this->owners, true)) {
            throw PermissionException::notAllowed('user', $user);
        }

        $resolved = $this->target($path);

        if (!@\chown($resolved, $user)) {
            throw PermissionException::failed('change the owner of', $path);
        }

        \clearstatcache(true, $resolved);
    }

    private function changeGroup(string $path, string $group): void
    {
        self::checkName('group', $group);

        if (!\in_array($group, $this->groups, true)) {
            throw PermissionException::notAllowed('group', $group);
        }

        $resolved = $this->target($path);

        if (!@\chgrp($resolved, $group)) {
            throw PermissionException::failed('change the group of', $path);
        }

        \clearstatcache(true, $resolved);
    }

    private function target(string $path): string
    {
        $resolved = $this->filesystem->resolve($path, true);

        if (\PHP_OS_FAMILY === 'Windows') {
            throw PermissionException::unsupportedPlatform();
        }

        return $resolved;
    }

    private static function checkName(string $kind, string $name): void
    {
        if (\preg_match(self::NAME, $name) !== 1) {
            throw PermissionException::invalidName($kind);
        }
    }
}
