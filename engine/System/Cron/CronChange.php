<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

/**
 * What installing a job did to the crontab.
 *
 * Unchanged is worth having separately: installing the same job on every
 * deployment is the normal case, and it must neither rewrite the crontab nor
 * be reported as a change somebody should look at.
 */
enum CronChange: string
{
    case Created = 'created';

    case Updated = 'updated';

    case Unchanged = 'unchanged';
}
