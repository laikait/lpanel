<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\System\Service\ServiceManager;

/**
 * Whether a systemd service is running, in systemd's own words.
 *
 * Exits 0 when the service is active and 1 otherwise, so a deployment script
 * can ask the question without parsing the table.
 */
final class SystemServiceStatusCommand
{
    public function __construct(private readonly ServiceManager $services) {}

    public function __invoke(Output $output, string $service): int
    {
        $status = $this->services->status($service);

        if (!$status->exists()) {
            $output->warning(\sprintf('systemd knows no unit named %s.', $status->unit));

            return 1;
        }

        $output->pairs([
            'Unit' => $status->unit,
            'Loaded' => $status->loadState,
            'Active' => $status->activeState . ' (' . $status->subState . ')',
            'At boot' => $status->unitFileState === '' ? '-' : $status->unitFileState,
        ]);

        return $status->isActive() ? 0 : 1;
    }
}
