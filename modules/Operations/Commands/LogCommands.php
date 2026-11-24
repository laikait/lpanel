<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Logs\LogViewer;

/** logs:* and stats:* from the shell: the same as the Logs and Statistics pages. */
final class LogCommands
{
    public function __construct(
        private readonly LogViewer $logs,
        private readonly Localization $localization,
    ) {}

    /** The logs that can be read (the system's, or --account's). */
    public function sources(Output $output, ?string $account = null): int
    {
        [$sources, $why] = $this->logs->sources($account);

        if ($sources === null) {
            return $this->fail($output, (string) $why);
        }

        foreach ($sources as $s) {
            $output->line(\sprintf('%-22s %-10s %s', $s['name'], $s['group'], $s['label']));
        }

        return 0;
    }

    /** The latest lines of a log. */
    public function tail(Output $output, string $source, ?string $account = null, ?string $lines = null, ?string $filter = null): int
    {
        [$log, $why] = $this->logs->read($account, $source, (int) ($lines ?? 200), (string) $filter);

        if ($log === null) {
            return $this->fail($output, (string) $why);
        }

        foreach ($log['lines'] as $line) {
            $output->line($line);
        }

        return 0;
    }

    /** The statistics of the access logs. */
    public function stats(Output $output, ?string $account = null, ?string $days = null, bool $refresh = false): int
    {
        [$r, $why] = $this->logs->report($account, (int) ($days ?? 30), $refresh);

        if ($r === null) {
            return $this->fail($output, (string) $why);
        }

        $output->line(\sprintf('%s, last %d days (made %s UTC)', $account ?? 'all sites', $r['days'], $r['made']));
        $output->line(\sprintf('requests %d, visitors %d, failed %d, sent %.1f MB', $r['requests'], $r['visitors'], $r['failed'], $r['bandwidth'] / 1048576));

        foreach (['pages' => 'pages', 'status' => 'status', 'referrers' => 'from', 'browsers' => 'browsers'] as $key => $label) {
            foreach (\array_slice((array) ($r[$key] ?? []), 0, 5) as $i) {
                $output->line(\sprintf('%-10s %6d  %s', $label, $i['hits'], $i['name']));
            }
        }

        return 0;
    }

    private function fail(Output $output, string $why): int
    {
        $output->error(\str_starts_with($why, 'Operations.') ? $this->localization->get($why) : $why);

        return 1;
    }
}
