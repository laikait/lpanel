<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Tasks\Tasks;

/** tasks:list from the shell: what the agent has done and is doing. */
final class TaskCommands
{
    public function __construct(
        private readonly Tasks $tasks,
        private readonly Localization $localization,
    ) {}

    /** The newest operations; --state running|done|failed|interrupted, --limit. */
    public function list(Output $output, ?string $state = null, string $limit = '30'): int
    {
        if ($state !== null && !\in_array($state, Tasks::STATES, true)) {
            $output->error('--state is ' . \implode(', ', Tasks::STATES) . '.');

            return 1;
        }

        $list = $this->tasks->list($state ?? '', \ctype_digit($limit) ? (int) $limit : 30);

        if ($list === null) {
            $output->error($this->localization->get('Operations.error_unreachable'));

            return 1;
        }

        $output->line(\sprintf('%-12s %-26s %-21s %s', 'STATE', 'OPERATION', 'STARTED (UTC)', 'NOTE'));

        foreach ($list as $t) {
            $output->line(\sprintf('%-12s %-26s %-21s %s', (string) $t['state'], (string) $t['op'], \substr(\str_replace('T', ' ', (string) $t['started']), 0, 19), (string) ($t['error_message'] ?? '')));
        }

        return 0;
    }
}
