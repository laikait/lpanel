<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Commands;

use App\Engine\Cli\Output;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\CustomerListRecord;

/**
 * A module-owned command.
 *
 * Notice what is absent. There is no base class, no $signature string, no
 * handle() method the framework insists on, and no $this->argument('since').
 * This is an ordinary invokable object: its collaborators arrive through the
 * constructor exactly as they would in a route handler, and the things the
 * operator typed arrive as typed parameters, coerced the same way a route
 * parameter is.
 *
 *   php laika customer:sync
 *   php laika customer:sync 2026-01-01 --dry-run --limit=2
 *   php laika customer:sync 2026-01-01 -dl2
 *
 * The exit code is the return value, because a command's result is read by a
 * shell script rather than by a person.
 */
final class SyncCustomers
{
    public function __construct(private readonly CustomerQuery $customers) {}

    public function __invoke(Output $output, string $since, int $limit, bool $dryRun): int
    {
        $page = $this->customers->listPage(1, $limit);

        if ($page->isEmpty()) {
            $output->line('Nothing to sync.');

            return 0;
        }

        $output->line(\sprintf(
            'Syncing %d of %d customer(s) changed since %s%s.',
            $page->count(),
            $page->total,
            $since,
            $dryRun ? ' (dry run)' : '',
        ));

        foreach ($page->items() as $customer) {
            if (!$customer instanceof CustomerListRecord) {
                continue;
            }

            $output->line(\sprintf('  %-4d %-20s %s', $customer->id, $customer->name, $customer->email));
        }

        if ($dryRun) {
            $output->warning('Dry run: nothing was written.');

            return 0;
        }

        $output->success(\sprintf('Synced %d customer(s).', $page->count()));

        return 0;
    }
}
