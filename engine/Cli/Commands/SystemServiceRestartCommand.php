<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;

/**
 * Restart a systemd service -- one the configuration allows restarting.
 *
 * The console is the administrative interface and asks for no identity: the
 * person at it already has a shell. What it does not have is a way around the
 * application's own policy. `system.services` must list the service with
 * "restart", exactly as it must for a module, and the change is audited the
 * same way.
 */
final class SystemServiceRestartCommand
{
    public function __construct(private readonly ServiceManager $services) {}

    public function __invoke(Output $output, string $service): int
    {
        $this->services->restart($service);
        $output->success(\sprintf('Restarted %s.', ServicePolicy::unit($service)));

        return 0;
    }
}
