<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Maintenance;

/** Leave maintenance mode. */
final class UpCommand
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function __invoke(Output $output): int
    {
        $output->line($this->maintenance->up() ? 'The application is up.' : 'The application was not down.');

        return 0;
    }
}
