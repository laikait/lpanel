<?php

declare(strict_types=1);

namespace App\Engine\System;

/** A system manager was asked for while configuration switches it off. */
final class SystemDisabledException extends SystemException
{
    public static function system(): self
    {
        return new self('System operations are switched off (system.enabled is false), so no system manager can be made.');
    }

    public static function cron(): self
    {
        return new self('Cron management is switched off (system.cron.enabled is false).');
    }
}
