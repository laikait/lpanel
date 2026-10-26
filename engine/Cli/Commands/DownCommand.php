<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Maintenance;

/**
 * Put the web side into maintenance mode: every request gets a 503 page,
 * except from the addresses allowed and browsers holding the bypass link.
 *
 * The console, queue workers and the scheduler keep running.
 */
final class DownCommand
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function __invoke(
        Output $output,
        int $retry = 60,
        ?string $message = null,
        ?string $allow = null,
        ?string $secret = null,
    ): int {
        $allowed = \array_values(\array_filter(\array_map('trim', \explode(',', $allow ?? '')), static fn(string $entry): bool => $entry !== ''));

        $this->maintenance->down($retry, $message ?? '', $allowed, $secret);

        $output->success('The application is down for maintenance: the web gets 503.');
        $output->pairs([
            'Retry-After' => $retry . ' seconds',
            'Allowed' => $allowed === [] ? 'nobody' : \implode(', ', $allowed),
            'Bypass link' => $secret === null || $secret === '' ? 'none' : '/?' . Maintenance::BYPASS_PARAMETER . '=' . \rawurlencode($secret),
        ]);
        $output->line('The console, queue workers and the scheduler keep running. Bring it back with: php laika up');

        return 0;
    }
}
