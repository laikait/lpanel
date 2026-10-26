<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

/**
 * Everything that changes a service. The value is the systemctl verb.
 *
 * Reading a service's status is not here: it changes nothing, and needs no
 * permission from a ServicePolicy.
 */
enum ServiceAction: string
{
    case Start = 'start';

    case Stop = 'stop';

    case Restart = 'restart';

    case Reload = 'reload';

    /** Start at boot. Does not start it now. */
    case Enable = 'enable';

    /** Do not start at boot. Does not stop it now. */
    case Disable = 'disable';
}
