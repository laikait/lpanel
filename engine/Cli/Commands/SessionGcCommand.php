<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Session\SessionManager;

/**
 * Delete sessions nobody can use any more.
 *
 * A command rather than a lottery, and that is the decision worth explaining.
 * PHP sweeps sessions probabilistically: roughly one request in a hundred pays
 * for scanning the whole directory, which means the cost lands on a random
 * user, at a random moment, and on the busiest sites it lands most often. Worse,
 * on Debian-derived systems the lottery is switched off and replaced by a cron
 * job, so the behaviour of a default installation depends on the distribution.
 *
 * Here it is a command with a schedule beside it:
 *
 *     $schedules->command('session:gc')->hourly();
 *
 * which runs off the request path, is visible in schedule:list, and can be
 * watched like anything else. Nothing expires because it was swept -- expiry is
 * decided on read, by the timestamps -- so a sweep that has not run for a week
 * costs disk space and not correctness.
 */
final class SessionGcCommand
{
    public function __invoke(SessionManager $sessions, Output $output, bool $quiet = false): int
    {
        $removed = $sessions->gc();

        if ($quiet) {
            return 0;
        }

        $output->heading('Session sweep');
        $output->line();
        $output->line('  Store    ' . $sessions->describe());
        $output->line('  Idle     ' . $this->period($sessions->idle()));
        $output->line('  Absolute ' . ($sessions->absolute() > 0 ? $this->period($sessions->absolute()) : 'no limit'));
        $output->line();
        $output->success(\sprintf('%d expired session%s removed.', $removed, $removed === 1 ? '' : 's'));

        return 0;
    }

    private function period(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return \sprintf('%d day%s', $seconds / 86400, $seconds === 86400 ? '' : 's');
        }

        if ($seconds % 3600 === 0) {
            return \sprintf('%d hour%s', $seconds / 3600, $seconds === 3600 ? '' : 's');
        }

        return \sprintf('%d minutes', (int) \round($seconds / 60));
    }
}
