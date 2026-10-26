<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

use App\Engine\System\SystemException;

/**
 * A service operation was refused, or systemd said no.
 *
 * systemctl's own stderr is never repeated: it names units, paths and polkit
 * details, and the exit code is what distinguishes the failures that matter
 * here -- not permitted, not found, failed to start.
 *
 * A unit systemd does not have is a ServiceNotFoundException, so that "this
 * host has no nginx" can be told apart from "nginx would not restart".
 */
class ServiceException extends SystemException
{
    /** systemctl's exit status for a unit it does not know (LSB: "not installed"). */
    public const EXIT_NOT_FOUND = 5;

    public static function notFound(string $unit, ServiceAction $action): ServiceNotFoundException
    {
        return new ServiceNotFoundException(\sprintf(
            'systemctl %s %s: systemd has no such unit. Check the name with systemctl list-unit-files.',
            $action->value,
            $unit,
        ));
    }

    public static function invalidName(): self
    {
        return new self(
            'The service name is invalid. A name is letters, digits, and : _ . @ -, starts with a letter or '
            . 'digit, and may end in .service; other unit types, such as targets, are not services.',
        );
    }

    public static function notPermitted(string $unit, ServiceAction $action): self
    {
        return new self(\sprintf(
            'Nothing permits "%s %s". Every change to a service is refused unless the service policy '
            . 'allows that action on that service.',
            $action->value,
            $unit,
        ));
    }

    public static function unsupportedPlatform(): self
    {
        return new self('Services are managed with systemd, which this operating system does not have. It is Linux-only.');
    }

    public static function unavailable(?int $exitCode): self
    {
        return new self(\sprintf(
            'systemd could not be asked (systemctl exited with %s). The machine may not be running systemd, '
            . 'as in many containers.',
            $exitCode === null ? 'no exit code' : (string) $exitCode,
        ));
    }

    public static function actionFailed(string $unit, ServiceAction $action, ?int $exitCode, bool $timedOut): self
    {
        return new self(\sprintf(
            'systemctl %s %s %s. Its journal says why: journalctl -u %2$s.',
            $action->value,
            $unit,
            $timedOut ? 'did not finish in time' : \sprintf('exited with %s', $exitCode === null ? 'no exit code' : (string) $exitCode),
        ));
    }
}
