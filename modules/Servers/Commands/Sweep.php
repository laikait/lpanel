<?php

declare(strict_types=1);

namespace App\Modules\Servers\Commands;

use App\Engine\Cli\Output;
use App\Modules\Servers\Store\ServerStore;

/** servers:sweep -- forget request ids past their replay window; retire keys whose overlap ended. Every minute. */
final class Sweep
{
    public function __construct(private readonly ServerStore $servers) {}

    public function __invoke(Output $output): int
    {
        $done = $this->servers->sweep();
        $output->line(\sprintf('Forgot %d request ids, retired %d agent keys.', $done['requests'], $done['keys']));

        return 0;
    }
}
