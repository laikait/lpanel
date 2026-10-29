<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cli;

use App\Engine\Cli\Output;

/** An invokable command handler, built by the container. */
final class CountItems
{
    public function __invoke(Output $output): int
    {
        $output->line('2 items.');

        return 0;
    }
}
