<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\System\SystemException;

/**
 * A command the allowlist does not permit.
 *
 * Nothing the caller passed is quoted -- not the executable path, not an
 * argument, not a variable's value -- for the reason every command message in
 * this layer gives: any of them can be the place a password was put. Positions
 * and names say which rule failed.
 */
final class CommandPolicyException extends SystemException
{
    public static function shellDisabled(): self
    {
        return new self(
            'Shell execution is switched off, so no ShellCommand runs. It is off unless system.shell.enabled is true '
            . '(or the executor was made with shell: true).',
        );
    }

    public static function tooManyRunning(int $limit): self
    {
        return new self(\sprintf(
            'All %d concurrency slots are in use, so the command was refused rather than made to wait. '
            . 'Work that can wait belongs on the queue.',
            $limit,
        ));
    }

    public static function executableNotAllowed(): self
    {
        return new self('The executable is not on the command allowlist, once symbolic links are resolved.');
    }

    public static function scriptNotAllowed(): self
    {
        return new self('The script is not on the command allowlist, once symbolic links are resolved.');
    }

    public static function argumentNotAllowed(int $position): self
    {
        return new self(\sprintf('Argument %d does not match the pattern the command allowlist permits for this program.', $position));
    }

    public static function workingDirectoryNotAllowed(): self
    {
        return new self('The working directory is not one the command allowlist permits for this program.');
    }

    public static function environmentNotAllowed(string $name): self
    {
        return new self(\sprintf('Environment variable %s is not one the command allowlist permits for this program.', $name));
    }

    public static function invalidRule(string $why): self
    {
        return new self(\sprintf('A command allowlist rule is unusable: %s.', $why));
    }
}
