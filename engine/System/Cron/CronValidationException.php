<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

/**
 * A cron job, or an owner, that could never be written: a bad id, schedule,
 * log path, or a command cron cannot honour.
 *
 * A mistake in code, caught before the crontab is read. The other cron
 * exceptions are about the crontab itself -- a block this framework did not
 * write, a line edited by hand, a crontab program that failed.
 */
final class CronValidationException extends CronException {}
