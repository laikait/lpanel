<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cli;

use App\Engine\Cli\Output;

/** The [class, method] handler form. */
final class TouchItem
{
    public function touch(Output $output, string $id): int
    {
        $output->line('touched ' . $id);

        return 0;
    }
}
