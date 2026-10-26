<?php

declare(strict_types=1);

namespace App\Engine\System\Systemd;

use App\Engine\System\SystemException;

/**
 * A unit could not be generated, installed or removed.
 *
 * Every message names only what the caller passed or what this framework
 * decided; systemctl's own output is never quoted, for the reason
 * SystemException gives.
 */
final class SystemdException extends SystemException
{
    public static function relativePath(string $path): self
    {
        return new self(\sprintf('The application path must be absolute; "%s" is not.', $path));
    }

    public static function noPhpBinary(string $sapi): self
    {
        return new self(\sprintf(
            'PHP_BINARY is not a PHP CLI under the %s SAPI; pass the path of the php binary with --php.',
            $sapi,
        ));
    }

    public static function lineBreak(): self
    {
        return new self(
            'A systemd unit value cannot contain a line break or a NUL byte: the rest would be read as '
            . 'another directive, which no quoting prevents.',
        );
    }

    public static function relativePhp(string $php): self
    {
        return new self(\sprintf('The PHP binary must be an absolute path; "%s" is not.', $php));
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self(\sprintf(
            '"%s" cannot start a unit name: use letters, digits, ".", "_" and "-", starting with a letter or digit.',
            $prefix,
        ));
    }

    public static function invalidAccount(string $kind, string $name): self
    {
        return new self(\sprintf('"%s" is not a %s name systemd accepts.', $name, $kind));
    }

    public static function runsAsRoot(): self
    {
        return new self(
            'The worker and the scheduler would run as root, because the application directory is owned by root '
            . 'and no --user was given. Pass --user=www-data (or whoever PHP-FPM runs as).',
        );
    }

    public static function noUser(): self
    {
        return new self('The owner of the application directory cannot be read here; pass --user.');
    }

    public static function invalidQueue(string $queue): self
    {
        return new self(\sprintf('"%s" is not a queue name: lower case letters, digits, "_" and "-".', $queue));
    }

    public static function notPrivileged(string $directory): self
    {
        return new self(\sprintf(
            'Installing units needs root: %s is not writable and systemctl would refuse. Run it with sudo, '
            . 'or use system:systemd:generate --write and copy the files yourself.',
            $directory,
        ));
    }

    public static function unsupportedPlatform(): self
    {
        return new self('systemd is not available on this machine. Use system:cron:install instead.');
    }

    public static function notOwned(string $unit, string $prefix): self
    {
        return new self(\sprintf(
            '%s is not one of this application\'s units (%s-worker@<queue>.service, %s-scheduler.timer), so it is left alone.',
            $unit,
            $prefix,
            $prefix,
        ));
    }

    public static function unwritable(string $path): self
    {
        return new self(\sprintf('%s could not be written.', $path));
    }

    public static function systemctlFailed(string $action, ?int $exitCode): self
    {
        return new self(\sprintf(
            'systemctl %s failed (exit code %s). journalctl -xe has systemd\'s reason.',
            $action,
            $exitCode === null ? 'none' : (string) $exitCode,
        ));
    }
}
